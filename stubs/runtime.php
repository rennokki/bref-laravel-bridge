<?php

use CacheWerk\BrefLaravelBridge\Octane;
use CacheWerk\BrefLaravelBridge\Secrets;
use CacheWerk\BrefLaravelBridge\Http\HttpHandler;
use CacheWerk\BrefLaravelBridge\Http\OctaneHandler;
use CacheWerk\BrefLaravelBridge\Queue\QueueHandler;
use CacheWerk\BrefLaravelBridge\StorageDirectories;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

require __DIR__ . '/../vendor/autoload.php';

StorageDirectories::create();

$runtime = $_ENV['APP_RUNTIME'] ?? null;

$ssmPrefix = $_ENV['APP_SSM_PREFIX'] ?? '';
$ssmParameters = $_ENV['APP_SSM_PARAMETERS'] ?? [];

$configCachePath = StorageDirectories::Path . '/bootstrap/cache/config.php';
$configIsCached = file_exists($configCachePath);

if ($ssmParameters && ! $configIsCached) {
    Secrets::injectIntoEnvironment($ssmPrefix, explode(',', $ssmParameters));
}

$app = require __DIR__ . '/../bootstrap/app.php';
$app->useStoragePath(StorageDirectories::Path);

if ($runtime === 'cli') {
    $kernel = $app->make(ConsoleKernel::class);
    $status = $kernel->handle($input = new ArgvInput, new ConsoleOutput);
    $kernel->terminate($input, $status);

    exit($status);
}

if ($runtime === 'queue') {
    $app->make(ConsoleKernel::class)->bootstrap();
    $config = $app->make('config');

    return $app->makeWith(QueueHandler::class, [
        'connection' => $config['queue.default'],
        'queue' => $config['queue.connections.sqs.queue'],
    ]);
}

$_ENV['APP_CONFIG_CACHE'] = $configCachePath;

if (! $configIsCached) {
    fwrite(STDERR, 'Caching Laravel configuration' . PHP_EOL);
    $app->make(ConsoleKernel::class)->call('config:cache');
}

if ($runtime === 'octane') {
    Octane::boot(realpath(__DIR__ . '/..'));

    return new OctaneHandler($app);
}

return new HttpHandler(
    $app->make(HttpKernel::class)
);