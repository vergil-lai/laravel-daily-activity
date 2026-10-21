<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    Carbon::setTestNow();
});

it('creates the portable activity table and reverses it', function () {
    Carbon::setTestNow('2026-10-22 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'laravel-daily-activity-migrations'])->assertSuccessful();
    $published = glob(database_path('migrations/*_create_daily_activities_table.php'));
    expect($published)->toHaveCount(1);
    Carbon::setTestNow('2026-10-22 12:00:02');
    $this->artisan('vendor:publish', ['--tag' => 'laravel-daily-activity-migrations'])->assertSuccessful();
    expect(glob(database_path('migrations/*_create_daily_activities_table.php')))->toBe($published);
    $migration = require $published[0];
    $migration->up();

    try {
        expect(Schema::getColumnListing('daily_activities'))->toEqualCanonicalizing([
            'id', 'action', 'subject_type', 'subject_id', 'year', 'days',
        ]);
        DB::table('daily_activities')->insert([
            ['subject_type' => 'user', 'subject_id' => 1, 'action' => 'login', 'year' => 2026, 'days' => str_repeat('0', 46)],
            ['subject_type' => 'user', 'subject_id' => 1, 'action' => 'check-in', 'year' => 2026, 'days' => str_repeat('0', 46)],
            ['subject_type' => 'device', 'subject_id' => 1, 'action' => 'login', 'year' => 2026, 'days' => str_repeat('0', 46)],
        ]);
        expect(DB::table('daily_activities')->count())->toBe(3);
        expect(fn () => DB::table('daily_activities')->insert([
            'subject_type' => 'user', 'subject_id' => 1, 'action' => 'login', 'year' => 2026, 'days' => str_repeat('0', 46),
        ]))->toThrow(QueryException::class);
    } finally {
        $migration->down();
        unlink($published[0]);
    }
    expect(Schema::hasTable('daily_activities'))->toBeFalse();
});
