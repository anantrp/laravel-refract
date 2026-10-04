<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Tests\Support\Env;
use Anantrp\Refract\Tests\Support\Jobs\RunAgentThenFail;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Jobs\RunAgent;

/**
 * The config of a working Langfuse destination with the given transport.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function lifecycleConfig(string $transport, array $config = []): array
{
    return [
        'refract.transport' => $transport,
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
        ...$config,
    ];
}

/**
 * Recreate the application as a web process (not running in the console).
 *
 * @param  array<string, mixed>  $config
 */
function bootWeb(array $config): void
{
    Env::set(['APP_RUNNING_IN_CONSOLE' => 'false']);

    test()->refreshApplicationWithConfig($config);

    expect(app()->runningInConsole())->toBeFalse();
}

/**
 * Handle a GET request through the HTTP kernel, without terminating it.
 *
 * @return Closure(): void The kernel's terminate step, run after the response is sent.
 */
function handleRequest(string $uri): Closure
{
    $kernel = app(Kernel::class);
    $request = Request::create($uri);

    /** @var Response $response */
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(200);

    return fn () => $kernel->terminate($request, $response);
}

/**
 * Run an agent with two steps and one tool: four spans.
 */
function runTimeAgent(): void
{
    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');
}

/**
 * Get the spans of each request sent so far, one list per request.
 *
 * @return list<list<array<string, mixed>>>
 */
function batches(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]->data()['resourceSpans'][0]['scopeSpans'][0]['spans'])->values()->all();
}

/**
 * Use the database queue on the test database, with an empty jobs table.
 */
function useDatabaseQueue(): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
        ],
        'queue.failed.driver' => 'null',
    ]);

    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

/**
 * Run the next job on the database queue the way a worker does.
 */
function workNextJob(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
}

/**
 * Send the command start and end events, as Laravel does outside unit tests.
 */
function withConsoleEvents(): void
{
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
}

afterEach(function () {
    Env::restore();
});

it('L1: a web request with transport sync exports after the response is sent, once', function () {
    bootWeb(lifecycleConfig('sync'));
    Http::fake();

    Route::get('/agent', function () {
        runTimeAgent();

        return 'ok';
    });

    $terminate = handleRequest('/agent');

    Http::assertNothingSent();

    $terminate();

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4);
});

it('L6: an async job is flushed at its end, and the next job on the worker starts with an empty buffer', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('sync'));
    Http::fake();
    useDatabaseQueue();

    Queue::push(new RunAgent);
    Queue::push(new RunAgent);

    workNextJob();

    Http::assertSentCount(1);

    workNextJob();

    Http::assertSentCount(2);

    [$first, $second] = batches();

    expect($first)->toHaveCount(4)
        ->and($second)->toHaveCount(4)
        ->and(array_intersect(array_column($first, 'spanId'), array_column($second, 'spanId')))->toBe([]);
});

it('L7: a job that fails after the agent ran still has its spans flushed, and the buffer is empty', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('sync'));
    Http::fake();
    useDatabaseQueue();

    Queue::push(new RunAgentThenFail);

    workNextJob();

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4)
        ->and(array_column(array_column(batches()[0], 'status'), 'code'))->not->toContain(2);

    app(Recorder::class)->flush();

    Http::assertSentCount(1);
});

it('L8: a sync-driver job dispatched in a web request does not flush, its spans leave with the request', function () {
    bootWeb(lifecycleConfig('sync'));
    Http::fake();

    Route::get('/agent', function () {
        Queue::connection('sync')->push(new RunAgent);

        runTimeAgent();

        return 'ok';
    });

    $terminate = handleRequest('/agent');

    Http::assertNothingSent();

    $terminate();

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(8);
});

it('L9: Artisan::call() in a web request does not flush, the spans leave with the request', function () {
    bootWeb(lifecycleConfig('sync'));
    Http::fake();
    withConsoleEvents();

    Artisan::command('refract-test:noop', fn () => 0);

    Route::get('/agent', function () {
        runTimeAgent();

        Artisan::call('refract-test:noop');

        return 'ok';
    });

    $terminate = handleRequest('/agent');

    Http::assertNothingSent();

    $terminate();

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4);
});

it('L10: a console command that runs an agent is flushed when it finishes', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('sync'));
    Http::fake();
    withConsoleEvents();

    Artisan::command('refract-test:agent', function () {
        runTimeAgent();

        Http::assertNothingSent();
    });

    Artisan::call('refract-test:agent');

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4);
});

it('L10: a command called from inside a command does not flush, open spans stay open', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('sync'));
    Http::fake();
    withConsoleEvents();

    Artisan::command('refract-test:noop', fn () => 0);

    Artisan::command('refract-test:agent', function () {
        TimeAgent::fakeTwoSteps();

        $called = false;

        foreach (TimeAgent::make()->stream('What time is it?') as $event) {
            if (! $called) {
                $called = true;

                Artisan::call('refract-test:noop');
            }
        }
    });

    Artisan::call('refract-test:agent');

    Http::assertSentCount(1);

    $spans = Otlp::spans();

    expect($spans)->toHaveCount(4)
        ->and(array_map(fn (array $span) => Otlp::attributes($span)['laravel.ai.abandoned'] ?? false, $spans))->not->toContain(true);
});
