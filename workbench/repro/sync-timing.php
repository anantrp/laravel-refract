<?php

/*
 * Times the sync drain against a real destination. Local only: not in the package, not in CI.
 *
 *   php workbench/repro/sync-timing.php <endpoint> <mode> <count>
 *
 * mode "agents": runs TimeAgent <count> times in this console process, as a
 * queue job would; each run's tree is exported when the run ends.
 * mode "big": sends one batch of <count> trees of about 1.5 MB each through
 * the drain, so the export makes more than one request.
 *
 * With REPRO_CACHE_DIR set, the app's default cache is a file cache there,
 * so separate runs of this script share it, as PHP-FPM requests would.
 *
 * Prints one JSON line: the seconds each run or batch took, and the total.
 */

use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Transport\Drain;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Providers\WorkbenchServiceProvider;

[, $endpoint, $mode, $count] = $argv + [null, null, 'agents', '3'];

$root = dirname(__DIR__, 2);

$loader = require $root.'/vendor/autoload.php';

// Load the package from this checkout, even when vendor is shared with another one.
$loader->setPsr4('Anantrp\\Refract\\', $root.'/src/');

foreach ([
    'REFRACT_DESTINATION' => 'otlp',
    'REFRACT_OTLP_ENDPOINT' => $endpoint,
    'REFRACT_DRAIN' => 'sync',
] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

$app = Application::create($root.'/vendor/orchestra/testbench-core/laravel', options: ['extra' => ['providers' => [
    AiServiceProvider::class,
    RefractServiceProvider::class,
    WorkbenchServiceProvider::class,
]]]);

$app['config']->set('logging.default', 'stderr');

if (getenv('REPRO_CACHE_DIR')) {
    $app['config']->set(['cache.default' => 'file', 'cache.stores.file.path' => getenv('REPRO_CACHE_DIR')]);
}

$times = [];

if ($mode === 'big') {
    $tree = fn () => [[
        'v' => 1, 'trace_id' => bin2hex(random_bytes(16)), 'span_id' => bin2hex(random_bytes(8)), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => ['agent' => 'TimeAgent'], 'content' => ['output' => [['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => bin2hex(random_bytes(750_000))]]]]],
        'context' => [], 'events' => [],
    ]];

    $start = hrtime(true);
    $app->make(Drain::class)->send(array_map(fn () => $tree(), range(1, (int) $count)));
    $times[] = round((hrtime(true) - $start) / 1e9, 2);
} else {
    for ($i = 0; $i < (int) $count; $i++) {
        TimeAgent::fakeTwoSteps();

        $start = hrtime(true);
        TimeAgent::make()->prompt('What time is it?');
        $times[] = round((hrtime(true) - $start) / 1e9, 2);
    }
}

echo json_encode(['src' => (new ReflectionClass(Drain::class))->getFileName(), 'mode' => $mode, 'count' => (int) $count, 'each' => $times, 'total' => round(array_sum($times), 2)]), "\n";
