<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application as LaravelApplication;
use Orchestra\Testbench\Foundation\Application;
use VergilLai\DailyActivity\DailyActivity;
use VergilLai\DailyActivity\Tests\Fixtures\Models\Subject;

require __DIR__.'/../../vendor/autoload.php';

[$script, $directory, $number, $date] = $argv;
$config = json_decode(file_get_contents($directory.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);

if (($config['driver'] === 'sqlite' && $config['database'] !== $directory.'/shared.sqlite')
    || ($config['driver'] !== 'sqlite' && $config['database'] !== 'daily_activity_test')) {
    throw new RuntimeException('Refusing to use an unrecognized test database.');
}
$bootstrap = $directory.'/bootstrap-'.$number;
mkdir($bootstrap.'/cache', 0777, true);
$app = Application::create(resolvingCallback: static function (LaravelApplication $app) use ($bootstrap): void {
    $app->useBootstrapPath($bootstrap);
});
$app['config']->set('database.default', 'worker');
$app['config']->set('database.connections.worker', $config);
$app['config']->set('cache.default', 'array');
$app['config']->set('app.timezone', 'UTC');
CarbonImmutable::setTestNow($date.' 12:00:00');
$connection = $app['db']->connection();
$waiting = false;
$connection->beforeExecuting(function (string $query) use (&$waiting, $directory, $number): void {
    if ($waiting || ! str_starts_with(strtolower($query), 'update')) {
        return;
    }
    $waiting = true;
    touch($directory.'/ready-'.$number);
    $deadline = microtime(true) + 15;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The concurrency barrier timed out.');
        }
        usleep(10000);
    }
});
$subject = Subject::findOrFail(1);
$result = (new DailyActivity)->recordFor($subject, 'login');
echo json_encode(['recorded' => $result], JSON_THROW_ON_ERROR);
