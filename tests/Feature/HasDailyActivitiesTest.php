<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use VergilLai\DailyActivity\DailyActivity;
use VergilLai\DailyActivity\Tests\Fixtures\Models\ConnectedSubject;
use VergilLai\DailyActivity\Tests\Fixtures\Models\CustomDailyActivity;
use VergilLai\DailyActivity\Tests\Fixtures\Models\ExternalDailyActivity;
use VergilLai\DailyActivity\Tests\Fixtures\Models\Subject;
use VergilLai\DailyActivity\Tests\Fixtures\Models\UlidSubject;
use VergilLai\DailyActivity\Tests\Fixtures\Models\UuidSubject;

beforeEach(function () {
    $this->createActivityTables();
    Schema::dropIfExists('custom_activities');
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');
});
afterEach(function () {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
    CarbonImmutable::setTestNow();
});

it('delegates recording and statistics and exposes both relationship directions', function () {
    $subject = Subject::create();
    expect($subject->recordDailyActivity('login'))->toBeTrue();
    expect($subject->recordDailyActivity('check-in'))->toBeTrue();
    expect($subject->currentActivityStreak('login'))->toBe(1);
    expect($subject->longestActivityStreak('login'))->toBe(1);
    expect($subject->dailyActivities)->toHaveCount(2);
    expect($subject->dailyActivities->first()->subject->is($subject))->toBeTrue();
});

it('uses morph map aliases', function () {
    Relation::enforceMorphMap(['person' => Subject::class]);
    $subject = Subject::create();
    $subject->recordDailyActivity('login');
    expect(DailyActivity::firstOrFail()->subject_type)->toBe('person');
    expect($subject->currentActivityStreak('login'))->toBe(1);
    expect(DailyActivity::firstOrFail()->subject->is($subject))->toBeTrue();
});

it('uses the configured model and table for every trait method', function () {
    Schema::rename('daily_activities', 'custom_activities');
    config()->set('daily-activity.model', CustomDailyActivity::class);
    $subject = Subject::create();
    expect($subject->recordDailyActivity('login'))->toBeTrue();
    expect($subject->currentActivityStreak('login'))->toBe(1);
    expect($subject->longestActivityStreak('login'))->toBe(1);
    expect($subject->dailyActivities->first())->toBeInstanceOf(CustomDailyActivity::class);
    expect($subject->recordDailyActivity('login', '2025-12-31'))->toBeTrue();
    expect($subject->hasActivityOn('login', '2025-12-31'))->toBeTrue();
    expect($subject->currentWeekActivity('login')['2026-10-22'])->toBeTrue();
    expect($subject->activityDaysInRange('login', '2026-10-01', '2026-10-31'))->toBe(1);
    expect($subject->currentMonthActivityDays('login'))->toBe(1);
    expect($subject->currentWeekActivityDays('login'))->toBe(1);
    expect($subject->longestActivityStreakInRange('login', '2026-10-01', '2026-10-31'))->toBe(1);
    expect($subject->currentMonthLongestActivityStreak('login'))->toBe(1);
    expect($subject->currentWeekLongestActivityStreak('login'))->toBe(1);
    expect($subject->activityDateRange('login', '2026-10-22', '2026-10-22'))->toBe(['2026-10-22' => true]);
    expect($subject->currentMonthActivity('login')['2026-10-22'])->toBeTrue();
    expect($subject->hasActivityOn('login', '2026-10-22'))->toBeTrue();
});

it('rejects an invalid configured model', function (string $class) {
    config()->set('daily-activity.model', $class);
    $subject = Subject::create();
    expect(fn () => $subject->recordDailyActivity('login'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $subject->dailyActivities())->toThrow(InvalidArgumentException::class);
})->with([stdClass::class, 'MissingActivityModel']);

it('supports published migrations adapted to uuidMorphs or ulidMorphs', function (string $model, string $kind) {
    Schema::drop('daily_activities');
    Schema::dropIfExists($kind.'_subjects');
    Schema::create($kind.'_subjects', function (Blueprint $table) use ($kind) {
        $table->$kind('id')->primary();
    });
    Schema::create('daily_activities', function (Blueprint $table) use ($kind) {
        $table->id();
        $table->string('action', 64);
        $method = $kind.'Morphs';
        $table->$method('subject');
        $table->smallInteger('year');
        $table->binary('days');
        $table->unique(['subject_type', 'subject_id', 'action', 'year'], 'daily_activities_subject_action_year_unique');
    });
    $subject = $model::create();
    expect($subject->recordDailyActivity('login'))->toBeTrue();
    expect($subject->recordDailyActivity('login'))->toBeFalse();
    expect($subject->currentActivityStreak('login'))->toBe(1);
    expect($subject->longestActivityStreak('login'))->toBe(1);
    expect($subject->dailyActivities->first()->subject->is($subject))->toBeTrue();
})->with([[UuidSubject::class, 'uuid'], [UlidSubject::class, 'ulid']]);

it('inherits subject connections without retaining state and respects explicit activity connections', function () {
    // 使用相互隔离的内存连接，便于发现意外使用默认连接的查询。
    foreach (['first', 'second'] as $name) {
        config()->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $schema = DB::connection($name)->getSchemaBuilder();
        $schema->create('subjects', function (Blueprint $table) {
            $table->id();
        });
        $schema->create('daily_activities', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->morphs('subject');
            $table->smallInteger('year');
            $table->binary('days');
            $table->unique(['subject_type', 'subject_id', 'action', 'year'], 'daily_activities_subject_action_year_unique');
        });
    }
    $first = Subject::on('first')->create();
    $second = Subject::on('second')->create();
    $activity = new DailyActivity;
    expect($activity->recordFor($first, 'login'))->toBeTrue();
    expect($activity->currentStreakFor($second, 'login'))->toBe(0);
    expect($activity->recordFor($second, 'login'))->toBeTrue();
    expect($first->dailyActivities()->count())->toBe(1);
    expect($second->dailyActivities()->count())->toBe(1);
    expect($activity->getConnectionName())->toBeNull();
    $activity->setConnection('first');
    expect($activity->recordFor($second, 'explicit'))->toBeTrue();
    expect(DB::connection('first')->table('daily_activities')->count())->toBe(2);
    expect(DB::connection('second')->table('daily_activities')->count())->toBe(1);
    config()->set('daily-activity.model', ExternalDailyActivity::class);
    expect($second->recordDailyActivity('configured'))->toBeTrue();
    expect($second->currentActivityStreak('configured'))->toBe(1);
    expect($second->longestActivityStreak('configured'))->toBe(1);
    expect($second->currentWeekActivity('configured')['2026-10-22'])->toBeTrue();
    expect($second->activityDaysInRange('configured', '2026-10-01', '2026-10-31'))->toBe(1);
    expect($second->currentMonthActivityDays('configured'))->toBe(1);
    expect($second->currentWeekActivityDays('configured'))->toBe(1);
    expect($second->longestActivityStreakInRange('configured', '2026-10-01', '2026-10-31'))->toBe(1);
    expect($second->currentMonthLongestActivityStreak('configured'))->toBe(1);
    expect($second->currentWeekLongestActivityStreak('configured'))->toBe(1);
    expect($second->activityDateRange('configured', '2026-10-22', '2026-10-22'))->toBe(['2026-10-22' => true]);
    expect($second->currentMonthActivity('configured')['2026-10-22'])->toBeTrue();
    expect($second->hasActivityOn('configured', '2026-10-22'))->toBeTrue();
    expect($second->dailyActivities()->count())->toBe(3);
    expect(DB::connection('second')->table('daily_activities')->count())->toBe(1);
    $connected = ConnectedSubject::create();
    expect($second->recordDailyActivity('configured', '2025-12-31'))->toBeTrue();
    expect($second->hasActivityOn('configured', '2025-12-31'))->toBeTrue();
    expect($connected->recordDailyActivity('login'))->toBeTrue();
    $stored = $connected->dailyActivities()->firstOrFail();
    expect($stored->getConnectionName())->toBe('first');
    expect($stored->subject->getConnectionName())->toBe('second');
    expect($stored->subject->is($connected))->toBeTrue();
});
