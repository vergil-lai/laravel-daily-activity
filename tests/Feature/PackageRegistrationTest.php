<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use VergilLai\DailyActivity\DailyActivity;
use VergilLai\DailyActivity\DailyActivityServiceProvider;

it('merges the configured activity model without replacing host overrides', function () {
    expect(config('daily-activity.model'))->toBe(DailyActivity::class);
    config()->set('daily-activity.model', 'App\\CustomActivity');
    (new DailyActivityServiceProvider(app()))->register();
    expect(config('daily-activity.model'))->toBe('App\\CustomActivity');
});

it('resolves independent Eloquent model instances', function () {
    expect(app(DailyActivity::class))->toBeInstanceOf(Model::class)
        ->not->toBe(app(DailyActivity::class));
});

it('merges the cache key without replacing host overrides', function () {
    expect(config('daily-activity.cache_key'))->toBe('daily-activity');
    config()->set('daily-activity.cache_key', 'custom-activity');
    (new DailyActivityServiceProvider(app()))->register();
    expect(config('daily-activity.cache_key'))->toBe('custom-activity');
});

it('publishes configuration using the documented filename', function () {
    $path = config_path('daily-activity.php');

    try {
        $this->artisan('vendor:publish', ['--tag' => 'laravel-daily-activity-config', '--force' => true])->assertSuccessful();
        expect(is_file($path))->toBeTrue();
        expect((require $path)['model'])->toBe(DailyActivity::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('registers only the real activity migration for publishing', function () {
    $paths = ServiceProvider::pathsToPublish(DailyActivityServiceProvider::class, 'laravel-daily-activity-migrations');
    expect($paths)->toHaveCount(1);
    expect(basename(array_key_first($paths)))->toContain('create_daily_activities_table.php');
    expect(Schema::hasTable('daily_activities'))->toBeFalse();
});
