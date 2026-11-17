<?php

/*
 * Runs one queue job that runs TimeAgent <count> times with the sync drain,
 * under a daemon worker (only a daemon enforces the timeout)
 * with a <timeout>. Local only: not in the package, not in CI.
 *
 *   php workbench/repro/queue-job.php <endpoint> <count> <timeout> <sqlite file>
 *
 * Prints one JSON line when the process ends, whether the job finished or
 * the worker killed it at its timeout: the seconds it ran, the agent runs
 * it finished, and the jobs left in failed_jobs.
 */

use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Transport\Drain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Providers\WorkbenchServiceProvider;

[, $endpoint, $count, $timeout, $database] = $argv + [null, null, '8', '30', null];

$root = dirname(__DIR__, 2);

$loader = require $root.'/vendor/autoload.php';

// Load the package from this checkout, even when vendor is shared with another one.
$loader->setPsr4('Anantrp\\Refract\\', $root.'/src/');

foreach (['REFRACT_DESTINATION' => 'otlp', 'REFRACT_OTLP_ENDPOINT' => $endpoint, 'REFRACT_DRAIN' => 'sync'] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

@unlink($database);
touch($database);

$app = Application::create($root.'/vendor/orchestra/testbench-core/laravel', options: ['extra' => ['providers' => [
    AiServiceProvider::class,
    RefractServiceProvider::class,
    WorkbenchServiceProvider::class,
]]]);

$app['config']->set([
    'logging.default' => 'stderr',
    'database.default' => 'repro',
    'database.connections.repro' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => ''],
    'queue.default' => 'database',
    'queue.connections.database' => ['driver' => 'database', 'connection' => 'repro', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 600],
    'queue.failed' => ['driver' => 'database-uuids', 'database' => 'repro', 'table' => 'failed_jobs'],
    'cache.default' => 'array',
]);

Schema::create('jobs', function (Blueprint $table) {
    $table->id();
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});

Schema::create('failed_jobs', function (Blueprint $table) {
    $table->id();
    $table->string('uuid')->unique();
    $table->text('connection');
    $table->text('queue');
    $table->longText('payload');
    $table->longText('exception');
    $table->timestamp('failed_at')->useCurrent();
});

class RunAgents implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public static int $finished = 0;

    public function __construct(public int $count, public int $timeout) {}

    public function handle(): void
    {
        for ($i = 0; $i < $this->count; $i++) {
            TimeAgent::fakeTwoSteps();
            TimeAgent::make()->prompt('What time is it?');
            static::$finished++;
        }
    }
}

$started = hrtime(true);

register_shutdown_function(function () use ($started, $count) {
    echo json_encode([
        'src' => (new ReflectionClass(Drain::class))->getFileName(),
        'seconds' => round((hrtime(true) - $started) / 1e9, 2),
        'runs_finished' => RunAgents::$finished.' of '.$count,
        'failed_jobs' => DB::table('failed_jobs')->count(),
    ]), "\n";
});

RunAgents::dispatch((int) $count, (int) $timeout);

Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--timeout' => (int) $timeout, '--tries' => 1]);
