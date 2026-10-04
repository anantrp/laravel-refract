<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\ClockRecorder;
use Anantrp\Refract\Tests\Support\Env;
use Anantrp\Refract\Tests\Support\Jobs\RunAgentThenFail;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\ExportSpans;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('L1: an agent run in an afterResponse dispatch is exported once, after it ran', function () {
    bootWeb(lifecycleConfig('sync'));
    Http::fake();

    Route::get('/agent', function () {
        dispatch(new RunAgent)->afterResponse();

        return 'ok';
    });

    $terminate = handleRequest('/agent');

    Http::assertNothingSent();

    $terminate();

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4);
});

it('L1: an agent run in a terminating callback added after boot is exported once', function () {
    bootWeb(lifecycleConfig('sync'));
    Http::fake();

    Route::get('/agent', function () {
        app()->terminating(fn () => runTimeAgent());

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

/**
 * Use a queue connection with the given driver as the app's default.
 */
function useQueueDriver(string $driver): void
{
    config(['queue.default' => 'refract-test', 'queue.connections.refract-test' => ['driver' => $driver]]);
}

/**
 * Get the exported attributes of the run span, read from every request sent so far.
 *
 * @return array<string, mixed>
 */
function exportedRun(): array
{
    $runs = array_values(array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], 'invoke_agent')));

    expect($runs)->toHaveCount(1);

    return $runs[0];
}

it('L2: a web request with transport queue dispatches one job after the response', function (string $driver) {
    bootWeb(lifecycleConfig('queue'));
    Http::fake();
    Queue::fake();
    useQueueDriver($driver);

    Route::get('/agent', function () {
        runTimeAgent();

        return 'ok';
    });

    $terminate = handleRequest('/agent');

    Queue::assertNothingPushed();

    $terminate();

    Queue::assertPushed(ExportSpans::class, 1);
    Http::assertNothingSent();
})->with(['redis', 'database', 'sqs', 'beanstalkd']);

it('L2: the job a web request dispatches on the database queue exports the batch in the worker', function () {
    bootWeb(lifecycleConfig('queue'));
    Http::fake();
    useDatabaseQueue();

    Route::get('/agent', function () {
        runTimeAgent();

        return 'ok';
    });

    $terminate = handleRequest('/agent');

    expect(DB::table('jobs')->count())->toBe(0);

    $terminate();

    expect(DB::table('jobs')->count())->toBe(1);
    Http::assertNothingSent();

    workNextJob();

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('L3: transport queue on the sync driver exports with sync at the flush point, no job', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue'));
    Http::fake();
    Queue::fake();
    useQueueDriver('sync');

    runTimeAgent();

    Http::assertNothingSent();

    app(Recorder::class)->flush();

    Queue::assertNothingPushed();
    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4);
});

it('L4: transport queue on the deferred or background driver exports with sync, no span lost', function (string $driver) {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue'));
    Http::fake();
    Queue::fake();
    useQueueDriver($driver);

    runTimeAgent();
    app(Recorder::class)->flush();

    Queue::assertNothingPushed();
    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4);
})->with(['deferred', 'background']);

it('L5: transport queue on the failover driver or an unknown custom driver exports with sync', function (string $driver) {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue'));
    Http::fake();
    Queue::fake();
    useQueueDriver($driver);

    runTimeAgent();
    app(Recorder::class)->flush();

    Queue::assertNothingPushed();
    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4);
})->with(['failover', 'my-custom-driver']);

it('L13: a batch too big for the queue is exported with sync after the response, with one warning', function () {
    bootWeb(lifecycleConfig('queue', [
        'refract.capture.content' => true,
        'refract.capture.max_bytes' => 2_000_000,
    ]));
    Http::fake();
    Queue::fake();
    useQueueDriver('redis');
    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    Route::get('/agent', function () {
        TimeAgent::fakeTwoSteps();

        // Random text does not compress much: well over 256 KB gzipped.
        TimeAgent::make()->prompt(bin2hex(random_bytes(200_000)));

        return 'ok';
    });

    $terminate = handleRequest('/agent');

    Http::assertNothingSent();

    $terminate();

    Queue::assertNothingPushed();
    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('too big for the queue');
});

it('L13: a big batch that compresses under the limit still goes through the queue', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue', [
        'refract.capture.content' => true,
        'refract.capture.max_bytes' => 2_000_000,
    ]));
    Http::fake();
    Queue::fake();
    useQueueDriver('redis');

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt(str_repeat('All work and no play. ', 30_000));
    app(Recorder::class)->flush();

    Queue::assertPushed(ExportSpans::class, 1);
    Http::assertNothingSent();
});

it('L14: captured text with invalid UTF-8 goes through the queue with the bytes as U+FFFD, batch not lost', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue', ['refract.capture.content' => true]));
    Http::fake();
    useDatabaseQueue();

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt("caf\xE9 au lait");
    app(Recorder::class)->flush();

    expect(DB::table('jobs')->count())->toBe(1);

    workNextJob();

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4)
        ->and(Otlp::attributes(exportedRun())['gen_ai.input.messages'])->toContain("caf\u{FFFD} au lait");
});

it('L15: floats 1.0 and 0.0 are exported as doubles through the queue, same as sync', function (string $transport) {
    $this->refreshApplicationWithConfig(lifecycleConfig($transport, [
        'refract.context.attributes' => ['score' => 'app.score', 'zero' => 'app.zero'],
    ]));
    Http::fake();
    useDatabaseQueue();

    Context::add('score', 1.0);
    Context::add('zero', 0.0);

    runTimeAgent();
    app(Recorder::class)->flush();

    if ($transport === 'queue') {
        Http::assertNothingSent();

        workNextJob();
    }

    $values = array_column(exportedRun()['attributes'], 'value', 'key');

    expect($values['app.score'])->toBe(['doubleValue' => 1.0])
        ->and($values['app.zero'])->toBe(['doubleValue' => 0.0]);
})->with(['sync', 'queue']);

it('L16: span times stay correct in a worker that runs for days, through the queue', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue'));
    Http::fake();
    useDatabaseQueue();

    $second = 1_000_000_000;
    $day = 86_400 * $second;

    $recorder = new ClockRecorder(app());

    // Three days after the worker started.
    $recorder->monotonicNow = 3 * $day;
    $recorder->start('first', 'invoke_agent', null, ['agent' => 'TimeAgent']);
    $recorder->monotonicNow += $second;
    $recorder->end('first');
    $recorder->monotonicNow += $second;
    $recorder->wallNow = $firstWall = 1_790_000_000 * $second;
    $recorder->flush();

    // Two days later. The wall clock was set 7 s ahead in between (NTP).
    $recorder->monotonicNow += 2 * $day;
    $recorder->start('second', 'invoke_agent', null, ['agent' => 'TimeAgent']);
    $recorder->monotonicNow += $second;
    $recorder->end('second');
    $recorder->monotonicNow += $second;
    $recorder->wallNow = $secondWall = $firstWall + 2 * $day + 2 * $second + 7 * $second;
    $recorder->flush();

    workNextJob();
    workNextJob();

    [[$firstSpan], [$secondSpan]] = batches();

    expect($firstSpan['startTimeUnixNano'])->toBe((string) ($firstWall - 2 * $second))
        ->and($firstSpan['endTimeUnixNano'])->toBe((string) ($firstWall - $second))
        ->and($secondSpan['startTimeUnixNano'])->toBe((string) ($secondWall - 2 * $second))
        ->and($secondSpan['endTimeUnixNano'])->toBe((string) ($secondWall - $second));
});

it('L11: a long command that makes 2,000 spans keeps 1,000, logs one warning, and memory stays flat', function () {
    $transport = $this->captureNeutralSpans();
    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    $recorder = app(Recorder::class);
    $call = ['agent' => 'TimeAgent', 'padding' => str_repeat('x', 1_024)];

    $record = function (int $from, int $to) use ($recorder, $call) {
        for ($i = $from; $i < $to; $i++) {
            $recorder->start("run:{$i}", 'invoke_agent', null, [...$call, 'run' => $i]);
            $recorder->event("run:{$i}", 'failover', $call);
            $recorder->end("run:{$i}");
        }
    };

    $record(0, 1_000);
    $atCap = memory_get_usage();

    $record(1_000, 2_000);
    $grown = memory_get_usage() - $atCap;

    $recorder->flush();

    expect($transport->spans)->toHaveCount(1_000)
        ->and(array_column(array_column($transport->spans, 'call'), 'run'))->toBe(range(0, 999))
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('1000 spans')
        // 1,000 more spans of over 2 KB each would add over 2 MB.
        ->and($grown)->toBeLessThan(100_000);
});

it('L11: after a flush the buffer takes spans again', function () {
    $transport = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    for ($i = 0; $i < 1_001; $i++) {
        $recorder->start("run:{$i}", 'invoke_agent', null, []);
        $recorder->end("run:{$i}");
    }

    $recorder->flush();
    runTimeAgent();
    $recorder->flush();

    expect($transport->spans)->toHaveCount(1_004);
});
