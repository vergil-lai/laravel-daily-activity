<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;
use VergilLai\DailyActivity\DailyActivityServiceProvider;

abstract class TestCase extends Orchestra
{
    private ?string $resourceDirectory = null;

    /**
     * 初始化测试环境，确认数据库安全边界并清理扩展包的测试表。
     */
    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->app['db']->connection();

        if ($connection->getDriverName() !== 'sqlite' && $connection->getDatabaseName() !== 'daily_activity_test') {
            throw new RuntimeException('Refusing to modify a database other than daily_activity_test.');
        }
        foreach (['custom_activities', 'daily_activities', 'subjects', 'uuid_subjects', 'ulid_subjects'] as $table) {
            $connection->getSchemaBuilder()->dropIfExists($table);
        }
    }

    /**
     * 注册待测扩展包的服务提供者。
     */
    protected function getPackageProviders($app): array
    {
        return [DailyActivityServiceProvider::class];
    }

    /**
     * 为测试配置临时资源目录、专用数据库连接、内存缓存和 UTC 时区。
     */
    protected function defineEnvironment($app): void
    {
        $this->resourceDirectory = sys_get_temp_dir().'/daily-activity-resources-'.bin2hex(random_bytes(8));
        mkdir($this->resourceDirectory.'/config', 0777, true);
        mkdir($this->resourceDirectory.'/database/migrations', 0777, true);
        $app->useConfigPath($this->resourceDirectory.'/config');
        $app->useDatabasePath($this->resourceDirectory.'/database');
        $driver = getenv('DAILY_ACTIVITY_TEST_DB') ?: 'sqlite';

        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported test database.');
        }
        $database = $driver === 'sqlite' ? ':memory:' : 'daily_activity_test';
        $app['config']->set('database.default', 'activity_testing');
        $app['config']->set('database.connections.activity_testing', [
            'driver' => $driver,
            'database' => $database,
            'host' => getenv('DAILY_ACTIVITY_TEST_HOST') ?: ($driver === 'pgsql' ? '/tmp/laravel-daily-activity-db/pgsql' : '127.0.0.1'),
            'port' => getenv('DAILY_ACTIVITY_TEST_PORT') ?: ($driver === 'pgsql' ? '55442' : '13316'),
            'username' => getenv('DAILY_ACTIVITY_TEST_USER') ?: ($driver === 'pgsql' ? 'activity_test' : 'root'),
            'password' => getenv('DAILY_ACTIVITY_TEST_PASSWORD') ?: ($driver === 'mysql' ? 'activity_test' : ''),
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.timezone', 'UTC');
    }

    /**
     * 释放测试环境，并确保删除测试期间创建的临时资源目录。
     */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->resourceDirectory !== null) {
                (new Filesystem)->deleteDirectory($this->resourceDirectory);
            }
        }
    }

    /**
     * 重建活动表和测试主体表，供各用例独立验证数据库行为。
     */
    public function createActivityTables(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        foreach (['daily_activities', 'subjects'] as $table) {
            $schema->dropIfExists($table);
        }
        (require __DIR__.'/../database/migrations/2026_10_22_000000_create_daily_activities_table.php')->up();
        $schema->create('subjects', function (Blueprint $table): void {
            $table->id();
        });
    }
}
