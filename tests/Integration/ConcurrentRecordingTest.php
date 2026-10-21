<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use VergilLai\DailyActivity\DailyActivity;
use VergilLai\DailyActivity\Tests\Fixtures\Models\Subject;

it('preserves concurrent updates after both workers read the original bitmap', function (array $dates, int $expectedTrue, int $streak) {
    $directory = sys_get_temp_dir().'/daily-activity-concurrency-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $processes = [];
    $original = config('database.default');

    try {
        $config = config('database.connections.'.$original);

        if ($config['driver'] === 'sqlite') {
            touch($directory.'/shared.sqlite');
            $config['database'] = $directory.'/shared.sqlite';
            $config['busy_timeout'] = 15000;
            $config['journal_mode'] = 'WAL';
        }
        config()->set('database.connections.concurrent', $config);
        DB::setDefaultConnection('concurrent');
        $this->createActivityTables();
        Subject::create();
        file_put_contents($directory.'/connection.json', json_encode($config, JSON_THROW_ON_ERROR));
        foreach ($dates as $index => $date) {
            $process = new Process([PHP_BINARY, __DIR__.'/../Support/record-activity-worker.php', $directory, (string) $index, $date]);
            $process->setTimeout(25);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
        while (count(glob($directory.'/ready-*')) < 2) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException($process->getErrorOutput().$process->getOutput());
                }
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not reach the update barrier.');
            }
            usleep(10000);
        }
        touch($directory.'/release');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['recorded'];
        }
        expect(count(array_filter($results)))->toBe($expectedTrue);
        expect(DailyActivity::count())->toBe(1);
        CarbonImmutable::setTestNow(max($dates).' 12:00:00');
        expect((new DailyActivity)->currentStreakFor(Subject::firstOrFail(), 'login'))->toBe($streak);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::disconnect('concurrent');
        DB::setDefaultConnection($original);
        CarbonImmutable::setTestNow();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    'same day and first annual row' => [['2026-10-21', '2026-10-21'], 1, 1],
    'different days of the same year' => [['2026-10-21', '2026-10-22'], 2, 2],
]);
