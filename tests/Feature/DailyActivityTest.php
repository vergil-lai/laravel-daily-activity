<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use VergilLai\DailyActivity\DailyActivity;
use VergilLai\DailyActivity\Tests\Fixtures\Models\OtherSubject;
use VergilLai\DailyActivity\Tests\Fixtures\Models\Subject;

beforeEach(function () {
    $this->createActivityTables();
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');
});
afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * 创建并返回一个已持久化的测试主体。
 */
function activitySubject(): Subject
{
    return Subject::create();
}

it('reads the writer while recording even when the read replica has not caught up', function () {
    $original = DB::getDefaultConnection();
    config()->set('database.connections.split', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    DB::setDefaultConnection('split');

    try {
        $this->createActivityTables();
        $subject = Subject::create();
        // 独立的只读副本尚未同步，连表结构都不存在。
        DB::connection()->setReadPdo(new PDO('sqlite::memory:'));
        $activity = new DailyActivity;
        expect($activity->recordFor($subject, 'login'))->toBeTrue();
        Cache::flush();
        expect($activity->recordFor($subject, 'login'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($original);
    }
});

/**
 * 将测试时间设为指定日期的中午，并记录主体的指定活动。
 */
function recordActivityOn(Subject $subject, string $date, string $action = 'login'): bool
{
    CarbonImmutable::setTestNow($date.' 12:00:00');

    return (new DailyActivity)->recordFor($subject, $action);
}

it('marks once with or without a cache hit and avoids all queries on a cache hit', function () {
    $subject = activitySubject();
    $model = new DailyActivity;
    expect($model->recordFor($subject, 'login'))->toBeTrue();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($model->recordFor($subject, 'login'))->toBeFalse();
    expect(DB::getQueryLog())->toBeEmpty();
    Cache::flush();
    DB::flushQueryLog();
    expect($model->recordFor($subject, 'login'))->toBeFalse();
    expect(collect(DB::getQueryLog())->filter(fn (array $query) => str_starts_with(strtolower($query['query']), 'update')))->toHaveCount(0);
    expect(DailyActivity::count())->toBe(1);
    expect($model->currentStreakFor($subject, 'login'))->toBe(1);
});

it('uses the configured cache key for writes and cache hits', function () {
    config()->set('daily-activity.cache_key', 'custom-activity');
    $subject = activitySubject();
    expect($subject->recordDailyActivity('login'))->toBeTrue();
    $key = array_key_first(Cache::getStore()->all());
    expect($key)->toMatch('/\Acustom-activity:login:[a-f0-9]{64}\z/');
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($subject->recordDailyActivity('login'))->toBeFalse();
    expect(DB::getQueryLog())->toBeEmpty();
});

it('only reads the current year when the current streak ends within it', function () {
    $subject = activitySubject();
    foreach (['2024-12-31', '2026-10-21', '2026-10-22'] as $date) {
        recordActivityOn($subject, $date);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($subject->currentActivityStreak('login'))->toBe(2);
    $queries = DB::getQueryLog();
    expect($queries)->toHaveCount(1);
    expect($queries[0]['bindings'])->toBe([$subject->getMorphClass(), $subject->getKey(), 'login', 2026]);
});

it('reads preceding years only while the current streak continues', function (string $today, array $years, int $expected, array $queriedYears) {
    $subject = activitySubject();
    foreach ($years as $year => $dates) {
        if ($dates === 'full') {
            DailyActivity::create([
                'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
                'action' => 'login', 'year' => $year, 'days' => str_repeat("\xff", 46),
            ]);
        } else {
            foreach ($dates as $date) {
                recordActivityOn($subject, $date);
            }
        }
    }
    CarbonImmutable::setTestNow($today.' 12:00:00');
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($subject->currentActivityStreak('login'))->toBe($expected);
    $queries = DB::getQueryLog();
    expect($queries)->toHaveCount(count($queriedYears));
    foreach ($queriedYears as $index => $year) {
        expect($queries[$index]['bindings'])->toBe([$subject->getMorphClass(), $subject->getKey(), 'login', $year]);
    }
})->with([
    'unmarked new year day' => ['2027-01-01', [2026 => ['2026-12-30', '2026-12-31']], 2, [2027, 2026]],
    'future marks do not extend the current streak' => ['2027-01-01', [2026 => ['2026-12-30', '2026-12-31'], 2027 => ['2027-01-03']], 2, [2027, 2026]],
    'missing current year after new year day' => ['2027-01-02', [2026 => ['2026-12-31']], 0, [2027]],
    'gap on new year day' => ['2027-01-02', [2026 => ['2026-12-31'], 2027 => ['2027-01-02']], 1, [2027]],
    'missing preceding year' => ['2027-01-02', [2025 => ['2025-12-31'], 2027 => ['2027-01-01', '2027-01-02']], 2, [2027, 2026]],
    'whole leap year and padding bits' => ['2025-01-01', [2023 => ['2023-12-31'], 2024 => 'full', 2025 => ['2025-01-01']], 368, [2025, 2024, 2023]],
    'whole non-leap year and reserved bits' => ['2026-01-01', [2024 => ['2024-12-31'], 2025 => 'full', 2026 => ['2026-01-01']], 367, [2026, 2025, 2024]],
    'no activity' => ['2026-10-22', [], 0, [2026]],
]);

it('starts the backward streak scan on the application timezone date', function () {
    config()->set('app.timezone', 'Asia/Shanghai');
    $subject = activitySubject();
    recordActivityOn($subject, '2026-12-31');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'));
    expect($subject->currentActivityStreak('login'))->toBe(1);
    expect($subject->recordDailyActivity('login'))->toBeTrue();
    expect($subject->currentActivityStreak('login'))->toBe(2);
});

it('isolates actions and morph classes for the same key', function () {
    $subject = activitySubject();
    $other = OtherSubject::findOrFail($subject->id);
    $model = new DailyActivity;
    expect($model->recordFor($subject, 'login'))->toBeTrue();
    expect($model->recordFor($subject, 'check-in'))->toBeTrue();
    $keys = array_keys(Cache::getStore()->all());
    expect($keys)->toHaveCount(2);
    expect($keys[0])->toMatch('/\Adaily-activity:login:[a-f0-9]{64}\z/');
    expect($keys[1])->toBe('daily-activity:check-in:'.substr($keys[0], -64));
    expect($model->recordFor($other, 'login'))->toBeTrue();
    expect(DailyActivity::count())->toBe(3);
    expect($model->currentStreakFor($subject, 'missing'))->toBe(0);
    expect($model->longestStreakFor($subject, 'missing'))->toBe(0);
});

it('backfills a date independently of today and caches it until the operation day ends', function () {
    $subject = activitySubject();
    expect($subject->recordDailyActivity('login', '2026-10-21'))->toBeTrue();
    expect($subject->hasActivityOn('login', '2026-10-21'))->toBeTrue();
    expect($subject->hasActivityOn('login', '2026-10-22'))->toBeFalse();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($subject->recordDailyActivity('login', '2026-10-21'))->toBeFalse();
    expect(DB::getQueryLog())->toBeEmpty();
    expect($subject->recordDailyActivity('login'))->toBeTrue();
    expect((new DailyActivity)->recordFor($subject, 'login', null))->toBeFalse();
    expect($subject->currentActivityStreak('login'))->toBe(2);
    expect(Cache::getStore()->all())->toHaveCount(2);
    $keys = array_keys(Cache::getStore()->all());
    CarbonImmutable::setTestNow('2026-10-23 00:00:00');
    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeFalse();
    }
    expect($subject->recordDailyActivity('login', '2026-10-21'))->toBeFalse();
});

it('backfills the correct year and leap day with Carbon inputs without changing them', function () {
    $subject = activitySubject();
    $date = Carbon::parse('2024-02-29 23:00:00');
    expect($subject->recordDailyActivity('login', $date))->toBeTrue();
    expect((new DailyActivity)->recordFor($subject, 'login', CarbonImmutable::parse('2025-12-31')))->toBeTrue();
    expect($subject->hasActivityOn('login', '2024-02-29'))->toBeTrue();
    expect($subject->hasActivityOn('login', '2025-12-31'))->toBeTrue();
    expect($subject->hasActivityOn('login', '2024-02-28'))->toBeFalse();
    expect($date->toDateTimeString())->toBe('2024-02-29 23:00:00');
});

it('normalizes explicit recording dates to the application timezone', function () {
    config()->set('app.timezone', 'Asia/Shanghai');
    $subject = activitySubject();
    $date = Carbon::parse('2025-12-31 16:30:00', 'UTC');
    expect($subject->recordDailyActivity('login', $date))->toBeTrue();
    expect($subject->hasActivityOn('login', '2026-01-01'))->toBeTrue();
    expect($subject->recordDailyActivity('login', '2025-12-31T16:30:00Z'))->toBeFalse();
    expect($date->timezoneName)->toBe('UTC');
    expect($date->toDateTimeString())->toBe('2025-12-31 16:30:00');
});

it('rejects invalid recording dates before writing or caching', function () {
    $subject = activitySubject();
    expect(fn () => $subject->recordDailyActivity('login', 'not-a-date'))
        ->toThrow(InvalidFormatException::class);
    expect(DailyActivity::count())->toBe(0);
    expect(Cache::getStore()->all())->toBeEmpty();
});

it('does not cache a backfill until commit and allows retry after rollback', function () {
    $subject = activitySubject();
    DB::beginTransaction();
    expect($subject->recordDailyActivity('login', '2025-12-31'))->toBeTrue();
    expect(Cache::getStore()->all())->toBeEmpty();
    DB::rollBack();
    expect($subject->hasActivityOn('login', '2025-12-31'))->toBeFalse();
    expect(Cache::getStore()->all())->toBeEmpty();
    DB::beginTransaction();
    expect($subject->recordDailyActivity('login', '2025-12-31'))->toBeTrue();
    expect(Cache::getStore()->all())->toBeEmpty();
    DB::commit();
    expect(Cache::getStore()->all())->toHaveCount(1);
    expect($subject->hasActivityOn('login', '2025-12-31'))->toBeTrue();
});

it('rejects invalid actions consistently before writing', function (string $action) {
    $subject = activitySubject();
    $model = new DailyActivity;
    foreach (['recordFor', 'currentStreakFor', 'longestStreakFor'] as $method) {
        expect(fn () => $model->$method($subject, $action))->toThrow(InvalidArgumentException::class);
    }
    expect(DailyActivity::count())->toBe(0);
})->with(['', 'Login', '登录', 'has space', str_repeat('a', 65)]);

it('rejects unsaved subjects', function () {
    expect(fn () => (new DailyActivity)->recordFor(new Subject, 'login'))->toThrow(InvalidArgumentException::class);
});

it('allows an unmarked today but expires the current streak after another missed day', function () {
    $subject = activitySubject();
    recordActivityOn($subject, '2026-10-19');
    recordActivityOn($subject, '2026-10-20');
    CarbonImmutable::setTestNow('2026-10-21');
    expect((new DailyActivity)->currentStreakFor($subject, 'login'))->toBe(2);
    CarbonImmutable::setTestNow('2026-10-22');
    expect((new DailyActivity)->currentStreakFor($subject, 'login'))->toBe(0);
    expect((new DailyActivity)->longestStreakFor($subject, 'login'))->toBe(2);
});

it('counts consecutive calendar days across year and leap boundaries', function (array $dates, int $expected) {
    $subject = activitySubject();
    foreach ($dates as $date) {
        recordActivityOn($subject, $date);
    }
    expect((new DailyActivity)->currentStreakFor($subject, 'login'))->toBe($expected);
    expect((new DailyActivity)->longestStreakFor($subject, 'login'))->toBe($expected);
})->with([
    [['2025-12-30', '2025-12-31', '2026-01-01', '2026-01-02'], 4],
    [['2024-02-28', '2024-02-29', '2024-03-01'], 3],
]);

it('breaks a run at a missing year', function () {
    $subject = activitySubject();
    foreach (['2024-12-30', '2024-12-31', '2026-01-01'] as $date) {
        recordActivityOn($subject, $date);
    }
    expect((new DailyActivity)->currentStreakFor($subject, 'login'))->toBe(1);
    expect((new DailyActivity)->longestStreakFor($subject, 'login'))->toBe(2);
});

it('uses high bit first and ignores future reserved and padding bits', function () {
    $subject = activitySubject();
    recordActivityOn($subject, '2026-01-01');
    expect(bin2hex(DailyActivity::firstOrFail()->days))->toBe('80'.str_repeat('00', 45));
    DailyActivity::firstOrFail()->update(['days' => str_repeat("\xff", 46)]);
    expect((new DailyActivity)->currentStreakFor($subject, 'login'))->toBe(1);
    expect((new DailyActivity)->longestStreakFor($subject, 'login'))->toBe(1);
    CarbonImmutable::setTestNow('2027-01-01');
    expect((new DailyActivity)->currentStreakFor($subject, 'login'))->toBe(365);
    expect((new DailyActivity)->longestStreakFor($subject, 'login'))->toBe(365);
});

it('uses the host timezone at midnight', function () {
    config()->set('app.timezone', 'Asia/Shanghai');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'));
    $subject = activitySubject();
    expect((new DailyActivity)->recordFor($subject, 'login'))->toBeTrue();
    expect(DailyActivity::firstOrFail()->year)->toBe(2027);
    expect(bin2hex(DailyActivity::firstOrFail()->days))->toBe('80'.str_repeat('00', 45));
});

it('publishes a success cache only after the outer transaction commits', function () {
    $subject = activitySubject();
    DB::beginTransaction();
    DB::beginTransaction();
    (new DailyActivity)->recordFor($subject, 'login');
    expect(Cache::getStore()->all())->toBeEmpty();
    DB::commit();
    expect(Cache::getStore()->all())->toBeEmpty();
    DB::commit();
    expect(Cache::getStore()->all())->toHaveCount(1);
    $key = array_key_first(Cache::getStore()->all());
    expect($key)->toMatch('/\Adaily-activity:login:[a-f0-9]{64}\z/');
});

it('does not cache a rolled back record', function () {
    $subject = activitySubject();
    DB::beginTransaction();
    (new DailyActivity)->recordFor($subject, 'login');
    DB::rollBack();
    expect(Cache::getStore()->all())->toBeEmpty();
    expect(DailyActivity::count())->toBe(0);
    expect((new DailyActivity)->recordFor($subject, 'login'))->toBeTrue();
});

it('keeps the original date and expiry when a transaction crosses midnight', function () {
    $subject = activitySubject();
    CarbonImmutable::setTestNow('2026-12-31 23:59:59');
    DB::beginTransaction();
    (new DailyActivity)->recordFor($subject, 'login');
    CarbonImmutable::setTestNow('2027-01-01 00:00:01');
    DB::commit();
    expect(DailyActivity::firstOrFail()->year)->toBe(2026);
    expect((new DailyActivity)->recordFor($subject, 'login'))->toBeTrue();
    expect(DailyActivity::count())->toBe(2);
});

it('expires the daily cache at end of day', function () {
    $subject = activitySubject();
    (new DailyActivity)->recordFor($subject, 'login');
    $key = array_key_first(Cache::getStore()->all());
    expect(Cache::has($key))->toBeTrue();
    CarbonImmutable::setTestNow('2026-10-23 00:00:00');
    expect(Cache::has($key))->toBeFalse();
});

it('does not publish success after a database failure', function () {
    $subject = activitySubject();
    Schema::drop('daily_activities');
    expect(fn () => (new DailyActivity)->recordFor($subject, 'login'))->toThrow(QueryException::class);
    expect(Cache::getStore()->all())->toBeEmpty();
});

it('reports cache failures but still persists the activity', function () {
    $subject = activitySubject();
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->twice();
    app()->instance(ExceptionHandler::class, $handler);
    Cache::shouldReceive('has')->once()->andThrow(new RuntimeException('Cache unavailable'));
    Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('Cache unavailable'));
    expect((new DailyActivity)->recordFor($subject, 'login'))->toBeTrue();
    expect(DailyActivity::count())->toBe(1);
});

it('surfaces repeated concurrent conflicts instead of silently losing a record', function () {
    $subject = activitySubject();
    $changing = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$changing): void {
        if ($changing || ! str_starts_with(strtolower($query), 'update')) {
            return;
        }
        $changing = true;
        $activity = DailyActivity::firstOrFail();
        $bytes = $activity->days;
        $bytes[0] = $bytes[0] === "\0" ? "\x80" : "\0";
        $activity->update(['days' => $bytes]);
        $changing = false;
    });
    expect(fn () => (new DailyActivity)->recordFor($subject, 'login'))
        ->toThrow(RuntimeException::class, 'after 5 concurrent update attempts');
    expect(Cache::getStore()->all())->toBeEmpty();
});
