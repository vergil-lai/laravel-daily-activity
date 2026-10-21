<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity;

use Illuminate\Support\ServiceProvider;

class DailyActivityServiceProvider extends ServiceProvider
{
    /**
     * 合并扩展包默认配置，保留宿主应用已设置的配置值。
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/daily-activity.php', 'daily-activity');
    }

    /**
     * 仅在控制台环境中注册配置文件和数据库迁移的资源发布标签。
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/daily-activity.php' => config_path('daily-activity.php'),
        ], ['laravel-daily-activity', 'laravel-daily-activity-config']);

        $this->publishes([
            __DIR__.'/../database/migrations/2026_10_22_000000_create_daily_activities_table.php' => database_path('migrations/2026_10_22_000000_create_daily_activities_table.php'),
        ], ['laravel-daily-activity', 'laravel-daily-activity-migrations']);
    }
}
