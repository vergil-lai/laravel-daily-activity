<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application as LaravelApplication;
use Orchestra\Testbench\Foundation\Application;
use VergilLai\DailyActivity\DailyActivity;
use VergilLai\DailyActivity\Tests\Fixtures\Models\Subject;

require __DIR__.'/../../vendor/autoload.php';

// 独立内存数据库，包含真实查询和模型加载开销，不使用宿主应用的数据。
$directory = sys_get_temp_dir().'/daily-activity-benchmark-'.bin2hex(random_bytes(8));
mkdir($directory.'/bootstrap/cache', 0777, true);
$app = Application::create(resolvingCallback: static function (LaravelApplication $app) use ($directory): void {
    $app->useBootstrapPath($directory.'/bootstrap');
});
$app['config']->set('database.default', 'benchmark');
$app['config']->set('database.connections.benchmark', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app['config']->set('app.timezone', 'UTC');
$connection = $app['db']->connection();
$schema = $connection->getSchemaBuilder();
(require __DIR__.'/../../database/migrations/2026_10_22_000000_create_daily_activities_table.php')->up();
$schema->create('subjects', function (Blueprint $table): void {
    $table->id();
});
$subject = Subject::create();
$model = new DailyActivity;
CarbonImmutable::setTestNow('2026-10-22 12:00:00');

// 保留优化前的扫描，包括当前连续天数的冗余计算和逐日 Carbon 创建。
$legacy = (function () use ($subject): int {
    $query = $this->queryFor($subject, 'login');
    $today = CarbonImmutable::now(config('app.timezone', 'UTC'))->startOfDay();
    $rows = $query->whereBetween('year', [1, $today->year])->orderBy('year')->get(['year', 'days']);
    $run = $longest = 0;
    $previousYear = null;
    $lastMarked = null;
    foreach ($rows as $row) {
        if ($previousYear !== $row->year - 1) {
            $run = 0;
        }
        $start = $today->setDate($row->year, 1, 1);
        $limit = $row->year === $today->year ? $today->dayOfYear : $start->daysInYear;
        $days = $row->days;
        for ($offset = 0; $offset < $limit; $offset++) {
            if (self::marked($days, $offset)) {
                $run++;
                $longest = max($longest, $run);
                $lastMarked = $start->addDays($offset);
            } elseif ($row->year !== $today->year || $offset !== $today->dayOfYear - 1) {
                $run = 0;
            }
        }
        $previousYear = $row->year;
    }
    $current = $lastMarked !== null && $lastMarked->greaterThanOrEqualTo($today->subDay()) ? $run : 0;

    return [$current, $longest][1];
})->bindTo($model, DailyActivity::class);
$optimized = fn (): int => $model->longestStreakFor($subject, 'login');
$iterations = 20;
$samples = 5;
$lines = [
    '# 最长连续天数性能对比',
    '',
    'PHP '.PHP_VERSION.'；SQLite '.$connection->selectOne('select sqlite_version() as version')->version.'；内存数据库；每项 '.$samples.' 轮，每轮 '.$iterations.' 次调用。',
    '每次调用包含一次 SQL 查询、Eloquent 加载及完整扫描。轮次交替执行新旧算法，结果取每次调用耗时的中位数。',
    '固定时间为 2026-10-22，年份连续并包含当年；不包含未来日期。预热后测量，查表初始化另列。',
    '',
    '| 年份数 | 位图 | 旧算法 ms/次 | 新算法 ms/次 | 倍数 | 最长天数 |',
    '| --- | --- | ---: | ---: | ---: | ---: |',
];

try {
    // 第一次调用单独计时，包含查表初始化；后面的耗时不包含这项固定成本。
    $started = hrtime(true);
    $optimized();
    $cold = (hrtime(true) - $started) / 1e6;
    foreach ([1, 10, 50] as $years) {
        foreach (['全零' => "\0", '交替标记' => "\x55", '全标记' => "\xff"] as $label => $byte) {
            DailyActivity::query()->delete();
            for ($year = 2027 - $years; $year <= 2026; $year++) {
                DailyActivity::create([
                    'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
                    'action' => 'login', 'year' => $year, 'days' => str_repeat($byte, 46),
                ]);
            }
            $expected = $legacy();

            if ($optimized() !== $expected) {
                throw new RuntimeException('Benchmark results differ.');
            }
            $timings = ['legacy' => [], 'optimized' => []];
            for ($sample = 0; $sample < $samples; $sample++) {
                $order = $sample % 2 === 0 ? ['legacy', 'optimized'] : ['optimized', 'legacy'];
                foreach ($order as $name) {
                    $call = $name === 'legacy' ? $legacy : $optimized;
                    $started = hrtime(true);
                    for ($iteration = 0; $iteration < $iterations; $iteration++) {
                        if ($call() !== $expected) {
                            throw new RuntimeException('Benchmark results changed.');
                        }
                    }
                    $timings[$name][] = (hrtime(true) - $started) / 1e6 / $iterations;
                }
            }
            sort($timings['legacy']);
            sort($timings['optimized']);
            $before = $timings['legacy'][2];
            $after = $timings['optimized'][2];
            $lines[] = sprintf('| %d | %s | %.3f | %.3f | %.2fx | %d |', $years, $label, $before, $after, $before / $after, $expected);
        }
    }
    $lines[] = '';
    $lines[] = sprintf('首次空数据调用（含查表初始化）：%.3f ms。', $cold);
    $lines[] = '本地 SQLite 结果用于对比实现，生产数据库延迟和实际活动密度会影响收益。';
    echo implode(PHP_EOL, $lines).PHP_EOL;
} finally {
    CarbonImmutable::setTestNow();
    $app->flush();
    (new Filesystem)->deleteDirectory($directory);
}
