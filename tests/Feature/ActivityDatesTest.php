<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

it('returns an inclusive daily map with one query across years and missing years', function () {
    $subject = Subject::create();
    foreach (['2024-12-31', '2026-01-01'] as $date) {
        CarbonImmutable::setTestNow($date);
        $subject->recordDailyActivity('login');
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $days = $subject->activityDateRange('login', '2024-12-31', '2026-01-02');
    expect(DB::getQueryLog())->toHaveCount(1);
    expect($days)->toHaveCount(368);
    expect($days['2024-12-31'])->toBeTrue();
    expect($days['2025-01-01'])->toBeFalse();
    expect($days['2025-12-31'])->toBeFalse();
    expect($days['2026-01-01'])->toBeTrue();
    expect($days['2026-01-02'])->toBeFalse();
});

it('handles leap days and custom key formats without mutating Carbon inputs', function () {
    $subject = Subject::create();
    CarbonImmutable::setTestNow('2024-02-29');
    $subject->recordDailyActivity('login');
    $start = Carbon::parse('2024-02-28 23:00:00');
    $end = CarbonImmutable::parse('2024-03-01 01:00:00');
    expect($subject->activityDateRange('login', $start, $end, 'd/m/Y'))->toBe([
        '28/02/2024' => false, '29/02/2024' => true, '01/03/2024' => false,
    ]);
    expect($start->toDateTimeString())->toBe('2024-02-28 23:00:00');
    expect($end->toDateTimeString())->toBe('2024-03-01 01:00:00');
});

it('returns the whole current month including unrecorded future dates', function () {
    $subject = Subject::create();
    $subject->recordDailyActivity('login');
    $days = $subject->currentMonthActivity('login');
    expect($days)->toHaveCount(31);
    expect(array_key_first($days))->toBe('2026-10-01');
    expect(array_key_last($days))->toBe('2026-10-31');
    expect($days['2026-10-22'])->toBeTrue();
    expect($days['2026-10-31'])->toBeFalse();
    expect($subject->currentMonthActivity('missing', 'd/m/Y')['22/10/2026'])->toBeFalse();
});

it('checks a single day while isolating subjects morph types and actions', function () {
    $subject = Subject::create();
    $subject->recordDailyActivity('login');
    expect($subject->hasActivityOn('login', '2026-10-22'))->toBeTrue();
    expect($subject->hasActivityOn('login', '2026-10-21'))->toBeFalse();
    expect($subject->hasActivityOn('login', '2025-10-22'))->toBeFalse();
    expect($subject->hasActivityOn('missing', '2026-10-22'))->toBeFalse();
    expect(Subject::create()->hasActivityOn('login', '2026-10-22'))->toBeFalse();
    expect(OtherSubject::findOrFail($subject->id)->hasActivityOn('login', '2026-10-22'))->toBeFalse();
});

it('normalizes date inputs and the current month to the application timezone', function () {
    config()->set('app.timezone', 'Asia/Shanghai');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'));
    $subject = Subject::create();
    $subject->recordDailyActivity('login');
    $date = Carbon::parse('2026-12-31 16:30:00', 'UTC');
    expect($subject->hasActivityOn('login', $date))->toBeTrue();
    expect($subject->activityDateRange('login', $date, '2027-01-01'))->toBe(['2027-01-01' => true]);
    expect(array_key_first($subject->currentMonthActivity('login')))->toBe('2027-01-01');
    expect($date->timezoneName)->toBe('UTC');
});

it('rejects reversed date ranges', function () {
    $subject = Subject::create();
    foreach (['activityDateRange', 'activityDaysInRange', 'longestActivityStreakInRange'] as $method) {
        expect(fn () => $subject->$method('login', '2026-10-23', '2026-10-22'))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('returns Monday through Sunday for the current week across a year boundary', function () {
    config()->set('app.timezone', 'Asia/Shanghai');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'));
    $subject = Subject::create();
    $subject->recordDailyActivity('login');
    expect($subject->currentWeekActivity('login'))->toBe([
        '2026-12-28' => false, '2026-12-29' => false, '2026-12-30' => false,
        '2026-12-31' => false, '2027-01-01' => true, '2027-01-02' => false, '2027-01-03' => false,
    ]);
    expect($subject->currentWeekActivity('login', 'd/m/Y')['01/01/2027'])->toBeTrue();
});

it('counts marked days and clips the longest streak to the requested range', function () {
    $subject = Subject::create();
    foreach (['2024-02-27', '2024-02-28', '2024-02-29', '2024-03-01', '2024-03-03'] as $date) {
        CarbonImmutable::setTestNow($date);
        $subject->recordDailyActivity('login');
    }
    expect($subject->activityDaysInRange('login', '2024-02-28', '2024-03-03'))->toBe(4);
    expect($subject->longestActivityStreakInRange('login', '2024-02-28', '2024-03-03'))->toBe(3);
    expect($subject->longestActivityStreakInRange('login', '2024-02-28', '2024-02-29'))->toBe(2);
    expect($subject->activityDaysInRange('login', '2024-02-29', '2024-02-29'))->toBe(1);
    expect($subject->activityDaysInRange('missing', '2024-02-28', '2024-03-03'))->toBe(0);
    expect($subject->longestActivityStreakInRange('missing', '2024-02-28', '2024-03-03'))->toBe(0);
});

it('counts month activity without including the preceding month in its longest streak', function () {
    $subject = Subject::create();
    foreach (['2026-09-30', '2026-10-01', '2026-10-02', '2026-10-04'] as $date) {
        CarbonImmutable::setTestNow($date);
        $subject->recordDailyActivity('login');
    }
    expect($subject->currentMonthActivityDays('login'))->toBe(3);
    expect($subject->currentMonthLongestActivityStreak('login'))->toBe(2);
    expect($subject->currentMonthActivityDays('missing'))->toBe(0);
    expect($subject->currentMonthLongestActivityStreak('missing'))->toBe(0);
});

it('counts weekly activity and clips streaks to Monday through Sunday across years', function () {
    config()->set('app.timezone', 'Asia/Shanghai');
    $subject = Subject::create();
    foreach (['2026-12-27', '2026-12-28', '2026-12-29', '2026-12-31', '2027-01-01'] as $date) {
        CarbonImmutable::setTestNow($date.' 12:00:00');
        $subject->recordDailyActivity('login');
    }
    CarbonImmutable::setTestNow('2027-01-03 12:00:00');
    expect($subject->currentWeekActivityDays('login'))->toBe(4);
    expect($subject->currentWeekLongestActivityStreak('login'))->toBe(2);
    expect($subject->currentWeekActivityDays('missing'))->toBe(0);
    expect($subject->currentWeekLongestActivityStreak('missing'))->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-03 16:30:00', 'UTC'));
    expect($subject->currentWeekActivityDays('login'))->toBe(0);
    expect($subject->currentWeekLongestActivityStreak('login'))->toBe(0);
});

it('preserves streaks across adjacent years and breaks them at missing years', function () {
    $subject = Subject::create();
    foreach (['2024-12-31', '2026-12-31', '2027-01-01'] as $date) {
        CarbonImmutable::setTestNow($date);
        $subject->recordDailyActivity('login');
    }
    expect($subject->activityDaysInRange('login', '2024-12-31', '2027-01-01'))->toBe(3);
    expect($subject->longestActivityStreakInRange('login', '2024-12-31', '2027-01-01'))->toBe(2);
});

it('validates subjects and actions for all date queries', function () {
    $model = new DailyActivity;
    foreach ([new Subject, Subject::create()] as $subject) {
        $action = $subject->exists ? 'Invalid Action' : 'login';
        expect(fn () => $model->dateRangeFor($subject, $action, '2026-10-01', '2026-10-22'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->currentMonthFor($subject, $action))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->hasActivityOnFor($subject, $action, '2026-10-22'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->currentWeekFor($subject, $action))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->daysInRangeFor($subject, $action, '2026-10-01', '2026-10-22'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->currentMonthDaysFor($subject, $action))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->longestStreakInRangeFor($subject, $action, '2026-10-01', '2026-10-22'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->currentMonthLongestStreakFor($subject, $action))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->currentWeekDaysFor($subject, $action))->toThrow(InvalidArgumentException::class);
        expect(fn () => $model->currentWeekLongestStreakFor($subject, $action))->toThrow(InvalidArgumentException::class);
    }
});
