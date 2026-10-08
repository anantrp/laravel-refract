<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Ai\NotesAgent;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
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
    return Http::recorded()->map(fn (array $pair) => Otlp::data($pair[0])['resourceSpans'][0]['scopeSpans'][0]['spans'])->values()->all();
}

/**
 * Use the database queue on the given database connection (the test database by default), with an empty jobs table.
 */
function useDatabaseQueue(string $connection = 'testing', bool $afterCommit = false): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database', 'connection' => $connection, 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90, 'after_commit' => $afterCommit,
        ],
        'queue.failed.driver' => 'null',
    ]);

    Schema::connection($connection)->dropIfExists('jobs');
    Schema::connection($connection)->create('jobs', function (Blueprint $table) {
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

it('L11: one run that makes 2,000 spans keeps 1,000, logs one warning, and memory stays flat', function () {
    $transport = $this->captureNeutralSpans();
    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    $recorder = app(Recorder::class);
    $call = ['tool' => 'CurrentTime', 'padding' => str_repeat('x', 1_024)];

    $recorder->start('run:long', 'invoke_agent', null, ['agent' => 'TimeAgent']);

    $record = function (int $from, int $to) use ($recorder, $call) {
        for ($i = $from; $i < $to; $i++) {
            $recorder->start("tool:{$i}", 'execute_tool', 'run:long', [...$call, 'call' => $i]);
            $recorder->event("tool:{$i}", 'failover', $call);
            $recorder->end("tool:{$i}");
        }
    };

    $record(0, 999);
    $atCap = memory_get_usage();

    $record(999, 1_999);
    $grown = memory_get_usage() - $atCap;

    $recorder->end('run:long');
    $recorder->flush();

    expect($transport->spans)->toHaveCount(1_000)
        ->and(array_column(array_column(array_slice($transport->spans, 0, 999), 'call'), 'call'))->toBe(range(0, 998))
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('1000 spans')
        // 1,000 more spans of over 2 KB each would add over 2 MB.
        ->and($grown)->toBeLessThan(100_000);
});

it('L11: while one open run holds the full buffer, dropped spans do not rescan the buffer', function () {
    $transport = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);
    $fulls = 0;
    $recorder->whenFull(function () use (&$fulls) {
        $fulls++;
    });

    $recorder->start('run:long', 'invoke_agent', null, []);

    for ($i = 0; $i < 2_999; $i++) {
        $recorder->start("tool:{$i}", 'execute_tool', 'run:long', []);
        $recorder->end("tool:{$i}");
    }

    // 2,000 dropped starts, and no tree could finish while the run stays open.
    expect($fulls)->toBe(1)
        ->and($transport->spans)->toBe([]);

    // A run ending can free the buffer, so the next full start tries again.
    $recorder->end('run:long');
    $recorder->start('run:next', 'invoke_agent', null, []);
    $recorder->end('run:next');

    expect($fulls)->toBe(2)
        ->and($transport->spans)->toHaveCount(1_000);

    $recorder->flush();

    expect($transport->spans)->toHaveCount(1_001);
});

it('L11: after a flush the buffer takes spans again', function () {
    $transport = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('run:long', 'invoke_agent', null, []);

    for ($i = 0; $i < 1_000; $i++) {
        $recorder->start("tool:{$i}", 'execute_tool', 'run:long', []);
        $recorder->end("tool:{$i}");
    }

    $recorder->end('run:long');
    $recorder->flush();
    runTimeAgent();
    $recorder->flush();

    expect($transport->spans)->toHaveCount(1_004);
});

it('L11: the run, steps and tool of a run dropped at the cap leave the dropped keys when they end', function () {
    $this->captureNeutralSpans();
    $recorder = app(Recorder::class);
    $keys = [];

    // These listeners run after Refract's, so each span has just started.
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$keys) {
        $keys[] = "run:{$event->invocationId}";
    });
    Event::listen(StartingStep::class, function (StartingStep $event) use (&$keys) {
        $keys[] = "step:{$event->invocationId}:{$event->stepNumber}";
    });
    Event::listen(InvokingTool::class, function (InvokingTool $event) use (&$keys) {
        $keys[] = "tool:{$event->toolInvocationId}";
    });

    // An open run holds the full buffer, so a full-buffer send frees no room.
    $recorder->start('run:long', 'invoke_agent', null, []);

    for ($i = 1; $i < 1_000; $i++) {
        $recorder->start("tool:{$i}", 'execute_tool', 'run:long', []);
        $recorder->end("tool:{$i}");
    }

    $dropped = [];

    Event::listen([PromptingAgent::class, StartingStep::class, InvokingTool::class], function () use ($recorder, &$keys, &$dropped) {
        $dropped[] = $recorder->isDropped(end($keys));
    });

    NotesAgent::fakeTwoSteps();
    NotesAgent::make()->prompt('What is new?');

    expect($keys)->toHaveCount(4)
        ->and($dropped)->toBe([true, true, true, true])
        ->and(array_filter($keys, $recorder->isDropped(...)))->toBe([]);
});

/**
 * Run the given callback inside an active app OTel span, as an app with its own tracing does.
 */
function insideAppTrace(Closure $callback): void
{
    $scope = Span::wrap(SpanContext::create(bin2hex(random_bytes(16)), bin2hex(random_bytes(8)), TraceFlags::SAMPLED))->activate();

    try {
        $callback();
    } finally {
        $scope->detach();
    }
}

it('L12: a long command sends its finished runs while it runs, with no span lost, inside one app trace', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('sync'));
    Http::fake();
    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    withConsoleEvents();

    $sentBeforeEnd = 0;

    Artisan::command('refract-test:many', function () use (&$sentBeforeEnd) {
        insideAppTrace(function () {
            for ($i = 0; $i < 300; $i++) {
                runTimeAgent();
            }
        });

        $sentBeforeEnd = count(Http::recorded());
    });

    Artisan::call('refract-test:many');

    $spans = Otlp::spans();

    expect($sentBeforeEnd)->toBeGreaterThanOrEqual(2)
        ->and(count(Http::recorded()))->toBeGreaterThan($sentBeforeEnd)
        ->and($spans)->toHaveCount(1_200)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(1_200)
        ->and(array_unique(array_column($spans, 'traceId')))->toHaveCount(1)
        ->and(array_map(fn (array $span) => Otlp::attributes($span)['laravel.ai.abandoned'] ?? false, $spans))->not->toContain(true)
        ->and($log->warnings)->toBe([]);

    // Each send holds whole runs: every span's parent is in the same request, or is the app's span.
    foreach (batches() as $batch) {
        $ids = array_column($batch, 'spanId');
        $runs = array_filter($batch, fn (array $span) => str_starts_with($span['name'], 'invoke_agent'));

        expect(count($batch))->toBe(4 * count($runs));

        foreach ($batch as $span) {
            if (! str_starts_with($span['name'], 'invoke_agent')) {
                expect($ids)->toContain($span['parentSpanId']);
            }
        }
    }
});

it('L12: a finished run is sent at the end of the next top-level run once 5 s passed since the last send', function () {
    $this->extenders = [Recorder::class => fn (Recorder $recorder, $app) => new ClockRecorder($app)];
    $this->refreshApplicationWithConfig(lifecycleConfig('sync'));
    Http::fake();

    $recorder = app(Recorder::class);
    $second = 1_000_000_000;

    runTimeAgent();
    $recorder->monotonicNow += 4 * $second;
    runTimeAgent();

    Http::assertNothingSent();

    $recorder->monotonicNow += $second;
    runTimeAgent();

    // The run that just ended stays for the next send, so events the SDK adds right after it are kept.
    expect(batches())->toHaveCount(1)
        ->and(batches()[0])->toHaveCount(8);

    $recorder->monotonicNow += 4 * $second;
    runTimeAgent();

    Http::assertSentCount(1);

    $recorder->flush();

    expect(batches())->toHaveCount(2)
        ->and(batches()[1])->toHaveCount(8);
});

it('L12: a web request does not send while it runs, it sends once after the response', function () {
    bootWeb(lifecycleConfig('sync'));
    Http::fake();

    Route::get('/many', function () {
        for ($i = 0; $i < 200; $i++) {
            runTimeAgent();
        }

        Http::assertNothingSent();

        return 'ok';
    });

    $terminate = handleRequest('/many');

    Http::assertNothingSent();

    $terminate();

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(800);
});

it('L12: a sub-agent run ending does not send its own tree away from its parent run', function () {
    $transport = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('run:parent', 'invoke_agent', null, []);
    $recorder->start('tool:sub', 'execute_tool', 'run:parent', []);

    for ($i = 0; $i < 600; $i++) {
        $recorder->start("step:{$i}", 'chat', 'tool:sub', []);
        $recorder->end("step:{$i}");
    }

    $recorder->start('run:sub', 'invoke_agent', 'tool:sub', []);
    $recorder->end('run:sub');

    expect($transport->spans)->toBe([]);
});

it('L12: only a top-level run ending fires the run-end send check, a sub-agent run ending does not', function () {
    $this->captureNeutralSpans();
    $recorder = app(Recorder::class);
    $ended = [];
    $recorder->whenRunEnds(function (string $key) use (&$ended) {
        $ended[] = $key;
    });

    $recorder->start('run:parent', 'invoke_agent', null, []);
    $recorder->start('tool:sub', 'execute_tool', 'run:parent', []);
    $recorder->start('run:sub', 'invoke_agent', 'tool:sub', []);
    $recorder->end('run:sub');

    expect($ended)->toBe([]);

    $recorder->end('tool:sub');
    $recorder->end('run:parent');

    expect($ended)->toBe(['run:parent']);
});

it('L12: runs under 1,000 spans each never hit the cap in a console process, the held run is sent when the buffer is full', function (int $runs, int $size) {
    $transport = $this->captureNeutralSpans();
    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    $recorder = app(Recorder::class);

    for ($run = 0; $run < $runs; $run++) {
        $recorder->start("run:{$run}", 'invoke_agent', null, ['agent' => 'Done']);

        for ($i = 1; $i < $size; $i++) {
            $recorder->start("tool:{$run}:{$i}", 'execute_tool', "run:{$run}", ['agent' => 'Done']);
            $recorder->end("tool:{$run}:{$i}");
        }

        $recorder->end("run:{$run}");
    }

    $recorder->flush();

    expect($transport->spans)->toHaveCount($runs * $size)
        ->and(array_unique(array_column($transport->spans, 'span_id')))->toHaveCount($runs * $size)
        ->and(array_unique(array_column($transport->spans, 'status')))->toBe(['ok'])
        ->and($log->warnings)->toBe([]);
})->with([
    '3 runs of 600 spans' => [3, 600],
    '4 runs of 400 spans' => [4, 400],
    '3 runs of 999 spans' => [3, 999],
]);

it('L12: a full buffer in a console process sends only finished runs, an open run stays whole and open', function () {
    $transport = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('run:done', 'invoke_agent', null, ['agent' => 'Done']);

    for ($i = 0; $i < 400; $i++) {
        $recorder->start("tool:done:{$i}", 'execute_tool', 'run:done', ['agent' => 'Done']);
        $recorder->end("tool:done:{$i}");
    }

    $recorder->end('run:done');
    $recorder->start('run:open', 'invoke_agent', null, ['agent' => 'Open']);

    for ($i = 0; $i < 700; $i++) {
        $recorder->start("tool:open:{$i}", 'execute_tool', 'run:open', ['agent' => 'Open']);
        $recorder->end("tool:open:{$i}");
    }

    expect($transport->spans)->toHaveCount(401)
        ->and(array_unique(array_column(array_column($transport->spans, 'call'), 'agent')))->toBe(['Done'])
        ->and($recorder->isOpen('run:open'))->toBeTrue();

    $recorder->end('run:open');
    $recorder->flush();

    expect($transport->spans)->toHaveCount(1_102);
});

it('L18: a partial send leaves open runs, their open spans and the reset state alone', function () {
    $transport = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);
    $resets = 0;
    $recorder->flushing(function () use (&$resets) {
        $resets++;
    });

    $recorder->start('run:open', 'invoke_agent', null, ['agent' => 'Open']);
    $recorder->start('step:open', 'chat', 'run:open', ['agent' => 'Open']);
    $recorder->start('step:done', 'chat', 'run:open', ['agent' => 'Open']);
    $recorder->end('step:done');

    for ($i = 0; $i < 600; $i++) {
        $recorder->start("run:{$i}", 'invoke_agent', null, ['agent' => 'Done']);
        $recorder->end("run:{$i}");
    }

    expect($transport->spans)->not->toBe([])
        ->and(array_unique(array_column(array_column($transport->spans, 'call'), 'agent')))->toBe(['Done'])
        ->and($resets)->toBe(0)
        ->and($recorder->isOpen('run:open'))->toBeTrue()
        ->and($recorder->isOpen('step:open'))->toBeTrue();

    $recorder->end('step:open');
    $recorder->end('run:open');
    $recorder->flush();

    $open = array_values(array_filter($transport->spans, fn (array $span) => $span['call']['agent'] === 'Open'));

    expect($transport->spans)->toHaveCount(603)
        ->and(array_column($open, 'status'))->toBe(['ok', 'ok', 'ok'])
        ->and($resets)->toBe(1);
});

it('L18: a run that failed over keeps its failover state through a partial send', function () {
    $transport = $this->captureNeutralSpans();
    config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test']]);

    // The events of one real run with a failover, replayed with other runs ending in between.
    $events = [];

    Event::listen('Laravel\Ai\Events\*', function (string $name, array $payload) use (&$events) {
        $events[] = $payload[0];
    });

    TimeAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => $provider->name() === 'openai'
        ? throw RateLimitedException::forProvider('openai')
        : 'It is 12:00.');
    TimeAgent::make()->prompt('What time is it?', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b']);

    app('events')->forget('Laravel\Ai\Events\*');
    app(Recorder::class)->flush();
    $transport->spans = [];

    $failover = array_search(true, array_map(fn (object $event) => $event instanceof AgentFailedOver, $events), true);
    $dispatcher = app('events');
    $recorder = app(Recorder::class);

    foreach (array_slice($events, 0, $failover + 1) as $event) {
        $copy = clone $event;
        $copy->invocationId = 'kept';
        $dispatcher->dispatch($copy);
    }

    for ($i = 0; $i < 600; $i++) {
        $recorder->start("run:{$i}", 'invoke_agent', null, ['agent' => 'Done']);
        $recorder->end("run:{$i}");
    }

    $sent = count($transport->spans);

    foreach (array_slice($events, $failover + 1) as $event) {
        $copy = clone $event;
        $copy->invocationId = 'kept';
        $dispatcher->dispatch($copy);
    }

    $recorder->flush();

    $kept = array_values(array_filter($transport->spans, fn (array $span) => ($span['call']['agent'] ?? null) !== 'Done'));

    expect($sent)->toBeGreaterThan(0)
        ->and(array_column($kept, 'kind'))->toBe(['chat', 'chat', 'invoke_agent'])
        ->and(array_column($kept, 'status'))->toBe(['error', 'ok', 'ok'])
        ->and($kept[2]['call']['provider'])->toBe('anthropic')
        ->and(array_column($kept[2]['events'], 'kind'))->toBe(['failover']);
});

/**
 * Get every span of the requests the destination took (a 2xx answer), in send order.
 *
 * @return list<array<string, mixed>>
 */
function deliveredSpans(): array
{
    $spans = [];

    foreach (Http::recorded() as [$request, $response]) {
        if ($response !== null && $response->successful()) {
            array_push($spans, ...Otlp::data($request)['resourceSpans'][0]['scopeSpans'][0]['spans']);
        }
    }

    return $spans;
}

/**
 * Work the database queue until it is empty, the way a worker does, at most the given number of jobs.
 */
function workAllJobs(int $most = 20): void
{
    for ($i = 0; $i < $most && DB::table('jobs')->count() > 0; $i++) {
        workNextJob();
    }
}

it('L19: an app rollback does not lose the spans a command sends while it runs, transport queue on the app database queue', function (bool $rollBack) {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue'));
    Http::fake();
    useDatabaseQueue();
    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    withConsoleEvents();

    Artisan::command('refract-test:many', function () use ($rollBack) {
        try {
            DB::transaction(function () use ($rollBack) {
                for ($i = 0; $i < 300; $i++) {
                    runTimeAgent();
                }

                if ($rollBack) {
                    throw new RuntimeException('The app rolls back.');
                }
            });
        } catch (RuntimeException) {
            //
        }
    });

    Artisan::call('refract-test:many');

    workAllJobs();

    $spans = Otlp::spans();

    expect($spans)->toHaveCount(1_200)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(1_200)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toBe([]);
})->with(['rolled back' => true, 'committed' => false]);

it('L19: a command still sends through a redis queue while it runs', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue'));
    Http::fake();
    Queue::fake();
    useQueueDriver('redis');
    withConsoleEvents();

    $pushedBeforeEnd = 0;

    Artisan::command('refract-test:many', function () use (&$pushedBeforeEnd) {
        for ($i = 0; $i < 300; $i++) {
            runTimeAgent();
        }

        $pushedBeforeEnd = Queue::pushed(ExportSpans::class)->count();
    });

    Artisan::call('refract-test:many');

    expect($pushedBeforeEnd)->toBeGreaterThanOrEqual(2);
    Http::assertNothingSent();
});

it('L20: a queue connection with after_commit pushes the export job at once, and an app rollback does not drop it', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig('queue', [
        'database.connections.refract-queue' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]));
    Http::fake();
    useDatabaseQueue('refract-queue', afterCommit: true);
    $jobs = fn () => DB::connection('refract-queue')->table('jobs')->count();

    runTimeAgent();

    $inTransaction = null;

    try {
        DB::transaction(function () use ($jobs, &$inTransaction) {
            app(Recorder::class)->flush();

            $inTransaction = $jobs();

            throw new RuntimeException('The app rolls back.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($inTransaction)->toBe(1)
        ->and($jobs())->toBe(1)
        ->and((new ExportSpans(''))->afterCommit)->toBeFalse();

    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4)
        ->and($jobs())->toBe(0);
});

/**
 * Recreate the application with a recorder whose clocks the test sets, and record warnings.
 *
 * @param  array<string, mixed>  $config
 */
function bootClocked(string $transport, array $config = []): WarningLog
{
    test()->extendBeforeBoot(Recorder::class, fn (Recorder $recorder, $app) => new ClockRecorder($app));
    test()->refreshApplicationWithConfig(lifecycleConfig($transport, $config));
    app(Recorder::class)->wallNow = 1_790_000_000_000_000_000;
    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    withConsoleEvents();

    return $log;
}

it('L21: a send while a command runs that the destination cannot take now keeps the spans, waits 5 s, then sends each span once', function () {
    $log = bootClocked('sync');
    Http::fakeSequence()->push('', 503)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);
    $second = 1_000_000_000;
    $sent = [];

    Artisan::command('refract-test:many', function () use ($recorder, $second, &$sent) {
        // One run a second: the run at 5 s sends the 4 runs before it, and the destination answers 503.
        for ($i = 0; $i < 5; $i++) {
            $recorder->monotonicNow += $second;
            runTimeAgent();
        }

        $sent[] = count(Http::recorded());

        // Over 500 finished spans at once, but nothing is sent while the 5 s backoff lasts.
        for ($i = 0; $i < 130; $i++) {
            runTimeAgent();
        }

        $recorder->monotonicNow += 5 * $second - 1;
        runTimeAgent();

        $sent[] = count(Http::recorded());

        $recorder->monotonicNow += 1;
        runTimeAgent();

        $sent[] = count(Http::recorded());
    });

    Artisan::call('refract-test:many');

    $spans = deliveredSpans();

    expect($sent)->toBe([1, 1, 2])
        ->and(Http::recorded())->toHaveCount(3)
        ->and($spans)->toHaveCount(4 * 137)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(4 * 137)
        ->and(array_map(fn (array $span) => Otlp::attributes($span)['laravel.ai.abandoned'] ?? false, $spans))->not->toContain(true)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('tried again')
        ->and($log->warnings[0])->not->toContain('dropped');
});

it('L21: kept spans keep the wall clock times of their first send', function () {
    // The OTLP destination sends times as they are (Langfuse moves tied start times apart).
    bootClocked('sync', ['refract.destination' => 'otlp', 'refract.destinations.otlp.endpoint' => 'https://otlp.test/v1/traces']);
    Http::fakeSequence()->push('', 503)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);
    $second = 1_000_000_000;

    // Run 1 from 1 s to 1 s; the wall clock reads 1,790,000,000 s at 5 s.
    $recorder->monotonicNow = $second;
    runTimeAgent();
    $recorder->monotonicNow = 5 * $second;
    runTimeAgent();

    // 10 s later the wall clock was set 7 s ahead (NTP): the kept spans keep their first times.
    $recorder->monotonicNow = 15 * $second;
    $recorder->wallNow += 17 * $second;
    runTimeAgent();
    $recorder->flush();

    $first = array_values(array_filter(deliveredSpans(), fn (array $span) => str_starts_with($span['name'], 'invoke_agent')))[0];

    expect($first['startTimeUnixNano'])->toBe((string) (1_790_000_000_000_000_000 - 4 * $second));
});

it('L21: a 429 with Retry-After 30 while a command runs delays the next send 30 s', function () {
    $log = bootClocked('sync');
    Http::fakeSequence()->push('', 429, ['Retry-After' => '30'])->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);
    $counts = [];

    Artisan::command('refract-test:many', function () use ($recorder, &$counts) {
        for ($t = 1; $t <= 40; $t++) {
            $recorder->monotonicNow += 1_000_000_000;
            runTimeAgent();
            $counts[$t] = count(Http::recorded());
        }
    });

    Artisan::call('refract-test:many');

    $spans = deliveredSpans();

    expect($counts[5])->toBe(1)
        ->and($counts[34])->toBe(1)
        ->and($counts[35])->toBe(2)
        ->and($spans)->toHaveCount(160)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(160)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('tried again');
});

it('L21: a send while a command runs that the destination rejects is dropped with its warning, not kept', function () {
    $log = bootClocked('sync');
    Http::fakeSequence()->push('bad', 400)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);

    Artisan::command('refract-test:many', function () use ($recorder) {
        for ($t = 1; $t <= 10; $t++) {
            $recorder->monotonicNow += 1_000_000_000;
            runTimeAgent();
        }
    });

    Artisan::call('refract-test:many');

    $spans = deliveredSpans();

    expect($spans)->toHaveCount(40 - 16)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(40 - 16)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('HTTP 400');
});

it('L21: when one part of a send gets through and the next cannot, only the second part is kept and sent again, once', function () {
    $log = bootClocked('sync');
    Http::fakeSequence()->push('', 200)->push('', 503)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);
    $second = 1_000_000_000;

    // Each run is about 2.5 MB of OTLP JSON: two runs do not fit one 4 MB part.
    $run = function (string $name) use ($recorder) {
        $recorder->start("run:{$name}", 'invoke_agent', null, ['agent' => $name]);
        $recorder->start("tool:{$name}", 'execute_tool', "run:{$name}", ['agent' => $name], content: ['result' => bin2hex(random_bytes(1_250_000))]);
        $recorder->end("tool:{$name}");
        $recorder->end("run:{$name}");
    };

    $run('A');
    $run('B');
    $recorder->monotonicNow += 5 * $second;
    $run('C');

    expect(Http::recorded())->toHaveCount(2);

    $recorder->monotonicNow += 5 * $second;
    $run('D');
    $recorder->flush();

    $spans = deliveredSpans();
    $runs = array_values(array_filter($spans, fn (array $span) => str_starts_with($span['name'], 'invoke_agent')));

    // A in part 1 (200), B in part 2 (503), then B and C (200, 200), then D at the flush.
    expect(Http::recorded())->toHaveCount(5)
        ->and($spans)->toHaveCount(8)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(8)
        ->and(count($runs))->toBe(4)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('tried again');
});

it('L21: when the parts of one send fail with and without Retry-After, the next send waits the longest Retry-After', function (string $transport, array $first, array $second) {
    $log = bootClocked($transport);
    useQueueDriver('sync');
    Http::fakeSequence()->push('', ...$first)->push('', ...$second)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);
    $sec = 1_000_000_000;

    // Each run is about 2.5 MB of OTLP JSON: two runs do not fit one 4 MB part.
    $run = function (string $name) use ($recorder) {
        $recorder->start("run:{$name}", 'invoke_agent', null, ['agent' => $name]);
        $recorder->start("tool:{$name}", 'execute_tool', "run:{$name}", ['agent' => $name], content: ['result' => bin2hex(random_bytes(1_250_000))]);
        $recorder->end("tool:{$name}");
        $recorder->end("run:{$name}");
    };

    // At 5 s, A and B are sent in two parts: one gets 429 Retry-After 60, the other 503.
    $run('A');
    $run('B');
    $recorder->monotonicNow = 5 * $sec;
    $run('C');

    expect(Http::recorded())->toHaveCount(2);

    // Past the 5 s rule, but inside the 60 s the destination asked for: no send.
    $recorder->monotonicNow = 10 * $sec;
    $run('D');
    $recorder->monotonicNow = 65 * $sec - 1;
    $run('E');

    expect(Http::recorded())->toHaveCount(2);

    // 60 s after the failed send: the next run end sends.
    $recorder->monotonicNow = 65 * $sec;
    $run('F');

    expect(count(Http::recorded()))->toBeGreaterThan(2);

    $recorder->flush();

    $spans = deliveredSpans();

    expect($spans)->toHaveCount(12)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(12)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('tried again');
})->with(['sync', 'queue on the sync driver' => 'queue'])->with([
    '429 Retry-After 60, then 503' => [[429, ['Retry-After' => '60']], [503]],
    '503, then 429 Retry-After 60' => [[503], [429, ['Retry-After' => '60']]],
]);

it('L21: a full flush that delivers the kept spans ends the wait, the next job sends at the 5 s rule again', function () {
    $log = bootClocked('sync');
    Http::fakeSequence()->push('', 429, ['Retry-After' => '300'])->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);
    $second = 1_000_000_000;

    // Job 1: the run-end send at 5 s gets 429 Retry-After 300 and keeps its spans.
    for ($t = 1; $t <= 5; $t++) {
        $recorder->monotonicNow = $t * $second;
        runTimeAgent();
    }

    expect(Http::recorded())->toHaveCount(1);

    // The job ends: the full flush delivers the kept spans (as JobAttempted does).
    $recorder->flush();

    expect(Http::recorded())->toHaveCount(2)
        ->and(Http::recorded()[1][1]->successful())->toBeTrue();

    // Job 2 on the same worker: runs 6 s apart send at their run end again (each run end holds its own run).
    $counts = [];

    foreach ([11, 17, 23] as $t) {
        $recorder->monotonicNow = $t * $second;
        runTimeAgent();
        $counts[] = count(Http::recorded());
    }

    $recorder->flush();

    $spans = deliveredSpans();

    expect($counts)->toBe([2, 3, 4])
        ->and($spans)->toHaveCount(32)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(32)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('tried again');
});

it('L21: kept spans go out with the final batch at the flush point, in one queue job that is retried', function () {
    $log = bootClocked('queue');
    useDatabaseQueue();
    $this->freezeTime();
    Http::fakeSequence()->push('', 503)->push('', 503)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);

    runTimeAgent();
    $recorder->monotonicNow += 5_000_000_000;
    runTimeAgent();

    // The database queue is never written while the process runs: the send was exported here, and kept.
    expect(Http::recorded())->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    $recorder->flush();

    expect(DB::table('jobs')->count())->toBe(1);

    workNextJob();

    expect(DB::table('jobs')->count())->toBe(1);

    $this->travel(10)->seconds();
    workNextJob();

    $spans = deliveredSpans();

    expect(Http::recorded())->toHaveCount(3)
        ->and(batches()[2])->toHaveCount(8)
        ->and($spans)->toHaveCount(8)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(8)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('tried again');
});

it('L21: fast runs that fill the buffer while a failed send waits still send every span once, the full buffer does not wait', function () {
    $log = bootClocked('sync');
    Http::fakeSequence()->push('', 503)->whenEmpty(Http::response('', 200));

    // The clock does not move: the run-end send waits for the whole command.
    Artisan::command('refract-test:many', function () {
        for ($i = 0; $i < 400; $i++) {
            runTimeAgent();
        }
    });

    Artisan::call('refract-test:many');

    $spans = deliveredSpans();

    expect($spans)->toHaveCount(1_600)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(1_600)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('tried again');
});

it('L21: while the destination stays down, a full buffer tries at most twice before the flush point', function () {
    $log = bootClocked('sync');
    Http::fake(fn () => Http::response('', 503));
    $beforeEnd = 0;

    // One run-end send (at 500 spans), then the full buffer tries. A failed
    // try leaves the buffer full of kept spans, so no new span gets in; the
    // clock does not move, so the wait never ends and only a run open at
    // that moment can end and try once more.
    Artisan::command('refract-test:many', function () use (&$beforeEnd) {
        for ($i = 0; $i < 400; $i++) {
            runTimeAgent();
        }

        $beforeEnd = count(Http::recorded());
    });

    Artisan::call('refract-test:many');

    expect($beforeEnd)->toBeLessThanOrEqual(3)
        ->and(Http::recorded())->toHaveCount($beforeEnd + 1)
        ->and(deliveredSpans())->toBe([]);
});

it('L21: after a failed full-buffer try with no run open, the next start once the wait is over tries again, and runs after the destination recovers are sent', function () {
    $log = bootClocked('sync');
    Http::fakeSequence()->push('', 503)->push('', 503)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);
    $counts = [];

    Artisan::command('refract-test:many', function () use ($recorder, &$counts) {
        // The run-end send at 500 spans gets 503, then the full-buffer try
        // at 1,000 spans gets 503: the buffer is full of kept spans, no run
        // is open, and the 50 runs after it are dropped.
        for ($i = 0; $i < 300; $i++) {
            runTimeAgent();
        }

        $counts[] = count(Http::recorded());

        // The destination is back; the 5 s wait is over.
        $recorder->monotonicNow += 5_000_000_000;

        for ($i = 0; $i < 50; $i++) {
            runTimeAgent();
        }

        $counts[] = count(Http::recorded());
    });

    Artisan::call('refract-test:many');

    $spans = deliveredSpans();
    $runs = array_filter($spans, fn (array $span) => str_starts_with($span['name'], 'invoke_agent'));

    // 1,000 kept spans in the try after the wait, the 50 later runs at the end of the command.
    expect($counts)->toBe([2, 3])
        ->and(Http::recorded())->toHaveCount(4)
        ->and($spans)->toHaveCount(1_000 + 50 * 4)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(1_200)
        ->and($runs)->toHaveCount(250 + 50)
        ->and($log->warnings)->toHaveCount(2);
});

it('L21: while the destination stays down, a full buffer with no run open tries at most once per wait, however many starts are dropped', function () {
    $log = bootClocked('sync');
    Http::fake(fn () => Http::response('', 503));
    $recorder = app(Recorder::class);
    $beforeEnd = 0;

    Artisan::command('refract-test:many', function () use ($recorder, &$beforeEnd) {
        // The run-end send at 500 spans, then the full-buffer try at 1,000: both fail.
        for ($i = 0; $i < 251; $i++) {
            runTimeAgent();
        }

        expect(Http::recorded())->toHaveCount(2);

        // 600 s, one run and 100 more dropped starts each second.
        for ($t = 1; $t <= 600; $t++) {
            $recorder->monotonicNow += 1_000_000_000;
            runTimeAgent();

            for ($i = 0; $i < 100; $i++) {
                $recorder->start("tool:{$t}:{$i}", 'execute_tool', null, []);
            }
        }

        $beforeEnd = count(Http::recorded());
    });

    Artisan::call('refract-test:many');

    // Each failed try starts a new 5 s wait: one try at 5 s, 10 s, ... 600 s.
    expect($beforeEnd)->toBe(2 + 600 / 5)
        ->and(Http::recorded())->toHaveCount($beforeEnd + 1)
        ->and(deliveredSpans())->toBe([])
        // Kept, buffer full, and the final batch dropped: one warning each.
        ->and($log->warnings)->toHaveCount(3);
});

it('L11: once a send frees room, the steps, tools and sub-agents of a run dropped at the cap are dropped too, not recorded as new traces', function () {
    bootClocked('sync');
    Http::fakeSequence()->push('', 503)->push('', 503)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);
    $counts = [];

    Artisan::command('refract-test:many', function () use ($recorder, &$counts) {
        // The run-end send at 500 spans and the full-buffer try at 1,000
        // both get 503: the buffer is full of kept spans.
        for ($i = 0; $i < 250; $i++) {
            runTimeAgent();
        }

        // Run X starts while the wait lasts: dropped.
        $recorder->start('run:x', 'invoke_agent', null, ['agent' => 'Dropped']);

        $counts[] = count(Http::recorded());

        // The model call takes over 5 s; the destination is back.
        $recorder->monotonicNow += 5_000_000_000;

        // The first step's start tries again, which frees the buffer.
        $recorder->start('step:x:1', 'chat', 'run:x', ['model' => 'dropped']);

        $counts[] = count(Http::recorded());

        $recorder->end('step:x:1');
        $recorder->start('tool:x', 'execute_tool', 'run:x', ['tool' => 'Dropped']);
        $recorder->start('run:y', 'invoke_agent', 'tool:x', ['agent' => 'Dropped'], contextFrom: 'run:x');
        $recorder->start('step:y:1', 'chat', 'run:y', ['model' => 'dropped']);
        $recorder->end('step:y:1');
        $recorder->end('run:y');
        $recorder->end('tool:x');
        $recorder->end('run:x');

        // A run after it is recorded as usual.
        runTimeAgent();
    });

    Artisan::call('refract-test:many');

    $spans = deliveredSpans();

    expect($counts)->toBe([2, 3])
        ->and($spans)->toHaveCount(1_000 + 4)
        ->and(array_filter($spans, fn (array $span) => str_contains($span['name'], 'Dropped') || str_contains($span['name'], 'dropped')))->toBe([]);
});

it('L11: a run dropped at the cap that fails over stays dropped once a send frees room, its next attempt too', function (bool $subAgent) {
    // The events of one real run with a failover, replayed under the invocation id x.
    config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test']]);
    $events = [];

    Event::listen('Laravel\Ai\Events\*', function (string $name, array $payload) use (&$events) {
        $events[] = $payload[0];
    });

    TimeAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => $provider->name() === 'openai'
        ? throw RateLimitedException::forProvider('openai')
        : 'It is 12:00.');
    TimeAgent::make()->prompt('What time is it?', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b']);

    $failover = array_search(true, array_map(fn (object $event) => $event instanceof AgentFailedOver, $events), true);

    $replay = function (array $events) use ($subAgent) {
        foreach ($events as $event) {
            $copy = clone $event;
            $copy->invocationId = 'x';

            // A sub-agent: the prompt names the tool that called it.
            if ($subAgent && $copy instanceof PromptingAgent) {
                $prompt = $copy->prompt;
                $copy->prompt = new AgentPrompt($prompt->agent, $prompt->prompt, $prompt->attachments, $prompt->provider, $prompt->model,
                    invocationId: 'x', parentToolInvocationId: 't');
            }

            app('events')->dispatch($copy);
        }
    };

    bootClocked('sync');
    config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test']]);
    Http::fakeSequence()->push('', 503)->push('', 503)->whenEmpty(Http::response('', 200));
    $recorder = app(Recorder::class);

    $counts = [];

    Artisan::command('refract-test:many', function () use ($recorder, $replay, $events, $failover, $subAgent, &$counts) {
        // The sub-agent's parent run and tool, open before the buffer fills.
        if ($subAgent) {
            $recorder->start('run:p', 'invoke_agent', null, ['agent' => 'Parent']);
            $recorder->start('tool:t', 'execute_tool', 'run:p', ['tool' => 'Dropped']);
        }

        // The run-end send at 500 spans and the full-buffer try at 1,000
        // both get 503: the buffer is full of kept spans (and the two open ones).
        for ($i = 0; $i < ($subAgent ? 249 : 250); $i++) {
            runTimeAgent();
        }

        if ($subAgent) {
            $recorder->start('run:fill', 'invoke_agent', null, ['agent' => 'Fill']);
            $recorder->end('run:fill');
            $recorder->start('run:fill', 'invoke_agent', null, ['agent' => 'Fill']);
            $recorder->end('run:fill');
        }

        // Run x starts while the wait lasts: dropped. Its first attempt fails over.
        $replay(array_slice($events, 0, $failover + 1));

        $counts[] = count(Http::recorded());

        // The first attempt took over 5 s; the destination is back.
        $recorder->monotonicNow += 5_000_000_000;

        // The next attempt: its first new span tries again, which frees the buffer.
        $replay(array_slice($events, $failover + 1));

        $counts[] = count(Http::recorded());

        if ($subAgent) {
            $recorder->end('tool:t');
            $recorder->end('run:p');
        }

        // A run after it is recorded as usual.
        runTimeAgent();
    });

    Artisan::call('refract-test:many');

    $spans = deliveredSpans();
    $ofX = array_filter($spans, fn (array $span) => (Otlp::attributes($span)['laravel.ai.invocation_id'] ?? null) === 'x'
        || str_contains($span['name'], 'gpt-a') || str_contains($span['name'], 'claude-b'));

    expect($counts)->toBe([2, 3])
        ->and($ofX)->toBe([])
        ->and(array_filter($spans, fn (array $span) => $span['name'] === 'invoke_agent TimeAgent'))->not->toBe([]);

    if ($subAgent) {
        expect(array_column($spans, 'name'))->toContain('invoke_agent Parent', 'execute_tool Dropped');
    }
})->with(['a top-level run' => false, 'a sub-agent under a tool still open' => true]);
