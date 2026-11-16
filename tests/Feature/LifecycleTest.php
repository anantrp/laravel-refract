<?php

use Anantrp\Refract\Capture\FlushPoints;
use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\ClockRecorder;
use Anantrp\Refract\Tests\Support\Env;
use Anantrp\Refract\Tests\Support\Jobs\RunAgentThenFail;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\WarningLog;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Jobs\RunAgent;

/**
 * The config of a working Langfuse destination.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function lifecycleConfig(array $config = []): array
{
    return [
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
function useDatabaseQueue(string $connection = 'testing'): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database', 'connection' => $connection, 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
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

it('a web request exports after the response is sent, once', function () {
    bootWeb(lifecycleConfig());
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

it('an agent run in an afterResponse dispatch is exported once, after it ran', function () {
    bootWeb(lifecycleConfig());
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

it('an agent run in a terminating callback added after boot is exported once', function () {
    bootWeb(lifecycleConfig());
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

it('an async job is flushed at its end, and the next job on the worker starts with an empty buffer', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig());
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

it('a job that fails after the agent ran still has its spans flushed, and the buffer is empty', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig());
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

it('a sync-driver job dispatched in a web request does not flush, its spans leave with the request', function () {
    bootWeb(lifecycleConfig());
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

it('Artisan::call() in a web request does not flush, the spans leave with the request', function () {
    bootWeb(lifecycleConfig());
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

it('a console command that runs an agent sends the run when it ends, not at the command end', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig());
    Http::fake();
    withConsoleEvents();

    Artisan::command('refract-test:agent', function () {
        runTimeAgent();

        Http::assertSentCount(1);
    });

    Artisan::call('refract-test:agent');

    Http::assertSentCount(1);
    expect(batches()[0])->toHaveCount(4);
});

it('a command called from inside a command does not flush, open spans stay open', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig());
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

it('floats 1.0 and 0.0 are exported as doubles', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig([
        'refract.context.attributes' => ['score' => 'app.score', 'zero' => 'app.zero'],
    ]));
    Http::fake();

    Context::add('score', 1.0);
    Context::add('zero', 0.0);

    runTimeAgent();

    $values = array_column(exportedRun()['attributes'], 'value', 'key');

    expect($values['app.score'])->toBe(['doubleValue' => 1.0])
        ->and($values['app.zero'])->toBe(['doubleValue' => 0.0]);
});

it('span times stay correct in a worker that runs for days', function () {
    $this->extendBeforeBoot(Recorder::class, fn (Recorder $recorder, $app) => new ClockRecorder($app));
    $this->refreshApplicationWithConfig(lifecycleConfig());
    Http::fake();

    $second = 1_000_000_000;
    $day = 86_400 * $second;

    $recorder = app(Recorder::class);

    // Three days after the worker started.
    $recorder->monotonicNow = 3 * $day;
    $recorder->start('first', 'invoke_agent', null, ['agent' => 'TimeAgent']);
    $recorder->monotonicNow += $second;
    $recorder->wallNow = $firstWall = 1_790_000_000 * $second;
    $recorder->end('first');

    // Two days later. The wall clock was set 7 s ahead in between (NTP).
    $recorder->monotonicNow += 2 * $day;
    $recorder->start('second', 'invoke_agent', null, ['agent' => 'TimeAgent']);
    $recorder->monotonicNow += $second;
    $recorder->wallNow = $secondWall = $firstWall + 2 * $day + $second + 7 * $second;
    $recorder->end('second');

    [[$firstSpan], [$secondSpan]] = batches();

    expect($firstSpan['startTimeUnixNano'])->toBe((string) ($firstWall - $second))
        ->and($firstSpan['endTimeUnixNano'])->toBe((string) $firstWall)
        ->and($secondSpan['startTimeUnixNano'])->toBe((string) ($secondWall - $second))
        ->and($secondSpan['endTimeUnixNano'])->toBe((string) $secondWall);
});

it('one run that makes 2,000 spans keeps 1,000, logs one warning, and memory stays flat', function () {
    $exporter = $this->captureNeutralSpans();
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

    expect($exporter->spans)->toHaveCount(1_000)
        ->and(array_column(array_column(array_slice($exporter->spans, 0, 999), 'call'), 'call'))->toBe(range(0, 998))
        ->and(end($exporter->spans)['call'])->toHaveKey('truncated', true)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('1000 spans')
        // 1,000 more spans of over 2 KB each would add over 2 MB.
        ->and($grown)->toBeLessThan(100_000);
});

it('a run over the per-run cap is truncated and marked, the next run in the same process is complete', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig());
    Http::fake();
    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    $recorder = app(Recorder::class);

    $recorder->start('run:big', 'invoke_agent', null, ['agent' => 'Big']);

    for ($i = 0; $i < 1_199; $i++) {
        $recorder->start("tool:{$i}", 'execute_tool', 'run:big', ['tool' => 'Big']);
        $recorder->end("tool:{$i}");
    }

    // Past the cap, a sub-agent run is dropped with the tool that called it, not made a trace of its own.
    $recorder->start('tool:sub', 'execute_tool', 'run:big', ['tool' => 'Big']);
    $recorder->start('run:sub', 'invoke_agent', 'tool:sub', ['agent' => 'Big']);

    expect($recorder->isDropped('tool:sub'))->toBeTrue()
        ->and($recorder->isDropped('run:sub'))->toBeTrue();

    $recorder->end('run:sub');
    $recorder->end('tool:sub');

    // A dropped span leaves the dropped keys when it ends.
    expect($recorder->isDropped('tool:sub'))->toBeFalse()
        ->and($recorder->isDropped('run:sub'))->toBeFalse();

    $recorder->end('run:big');

    runTimeAgent();

    $spans = deliveredSpans();
    $big = array_values(array_filter($spans, fn (array $span) => str_contains($span['name'], 'Big')));
    $next = array_values(array_filter($spans, fn (array $span) => ! str_contains($span['name'], 'Big')));
    $runs = array_values(array_filter($spans, fn (array $span) => str_starts_with($span['name'], 'invoke_agent')));

    expect($big)->toHaveCount(1_000)
        ->and(array_column($runs, 'name'))->toBe(['invoke_agent Big', 'invoke_agent TimeAgent'])
        ->and(Otlp::attributes($runs[0]))->toHaveKey('laravel.ai.truncated', true)
        ->and(array_filter($big, fn (array $span) => isset(Otlp::attributes($span)['laravel.ai.truncated'])))->toHaveCount(1)
        ->and($next)->toHaveCount(4)
        ->and(array_filter($next, fn (array $span) => isset(Otlp::attributes($span)['laravel.ai.truncated'])))->toBe([])
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('laravel.ai.truncated');
});

it('a sub-agent run counts toward the cap of the run that called it', function () {
    $exporter = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('run:top', 'invoke_agent', null, ['agent' => 'Top']);
    $recorder->start('tool:sub', 'execute_tool', 'run:top', ['agent' => 'Top']);
    $recorder->start('run:sub', 'invoke_agent', 'tool:sub', ['agent' => 'Sub']);

    for ($i = 0; $i < 1_100; $i++) {
        $recorder->start("step:{$i}", 'chat', 'run:sub', ['agent' => 'Sub']);
        $recorder->end("step:{$i}");
    }

    $recorder->end('run:sub');
    $recorder->end('tool:sub');
    $recorder->end('run:top');
    $recorder->flush();

    $truncated = array_values(array_filter($exporter->spans, fn (array $span) => isset($span['call']['truncated'])));

    expect($exporter->spans)->toHaveCount(1_000)
        ->and(array_column(array_column($truncated, 'call'), 'agent'))->toBe(['Top']);
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

it('a command sends each top-level run when it ends, each whole in its own request, inside one app trace', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig());
    Http::fake();
    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    withConsoleEvents();

    $sent = [];

    Artisan::command('refract-test:many', function () use (&$sent) {
        insideAppTrace(function () use (&$sent) {
            for ($i = 0; $i < 3; $i++) {
                runTimeAgent();

                $sent[] = count(Http::recorded());
            }
        });
    });

    Artisan::call('refract-test:many');

    $spans = Otlp::spans();

    expect($sent)->toBe([1, 2, 3])
        ->and(Http::recorded())->toHaveCount(3)
        ->and($spans)->toHaveCount(12)
        ->and(array_unique(array_column($spans, 'spanId')))->toHaveCount(12)
        ->and(array_unique(array_column($spans, 'traceId')))->toHaveCount(1)
        ->and(array_map(fn (array $span) => Otlp::attributes($span)['laravel.ai.abandoned'] ?? false, $spans))->not->toContain(true)
        ->and($log->warnings)->toBe([]);

    // Each request holds one whole run: every span's parent is in the same request, or is the app's span.
    foreach (batches() as $batch) {
        $ids = array_column($batch, 'spanId');

        expect($batch)->toHaveCount(4);

        foreach ($batch as $span) {
            if (! str_starts_with($span['name'], 'invoke_agent')) {
                expect($ids)->toContain($span['parentSpanId']);
            }
        }
    }
});

it('a web request does not send while it runs, it sends once after the response', function () {
    bootWeb(lifecycleConfig());
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

it('a sub-agent run ending does not send its own tree away from its parent run', function () {
    $exporter = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('run:parent', 'invoke_agent', null, []);
    $recorder->start('tool:sub', 'execute_tool', 'run:parent', []);

    for ($i = 0; $i < 600; $i++) {
        $recorder->start("step:{$i}", 'chat', 'tool:sub', []);
        $recorder->end("step:{$i}");
    }

    $recorder->start('run:sub', 'invoke_agent', 'tool:sub', []);
    $recorder->end('run:sub');

    expect($exporter->spans)->toBe([]);
});

it('only a top-level span ending hands its tree on, with its sub-agent runs in it', function () {
    $exporter = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('run:parent', 'invoke_agent', null, []);
    $recorder->start('tool:sub', 'execute_tool', 'run:parent', []);
    $recorder->start('run:sub', 'invoke_agent', 'tool:sub', []);
    $recorder->end('run:sub');
    $recorder->end('tool:sub');

    expect($exporter->trees)->toBe([]);

    $recorder->end('run:parent');

    expect($exporter->trees)->toHaveCount(1)
        ->and(array_column($exporter->trees[0], 'kind'))->toBe(['invoke_agent', 'execute_tool', 'invoke_agent']);
});

it('runs under 1,000 spans each never hit the cap in a console process', function (int $runs, int $size) {
    $exporter = $this->captureNeutralSpans();
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

    expect($exporter->spans)->toHaveCount($runs * $size)
        ->and(array_unique(array_column($exporter->spans, 'span_id')))->toHaveCount($runs * $size)
        ->and(array_unique(array_column($exporter->spans, 'status')))->toBe(['ok'])
        ->and($log->warnings)->toBe([]);
})->with([
    '3 runs of 600 spans' => [3, 600],
    '4 runs of 400 spans' => [4, 400],
    '3 runs of 999 spans' => [3, 999],
]);

it('a console process sends a finished run at once, an open run stays whole and open', function () {
    $exporter = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('run:open', 'invoke_agent', null, ['agent' => 'Open']);

    for ($i = 0; $i < 700; $i++) {
        $recorder->start("tool:open:{$i}", 'execute_tool', 'run:open', ['agent' => 'Open']);
        $recorder->end("tool:open:{$i}");
    }

    $recorder->start('run:done', 'invoke_agent', null, ['agent' => 'Done']);

    for ($i = 0; $i < 400; $i++) {
        $recorder->start("tool:done:{$i}", 'execute_tool', 'run:done', ['agent' => 'Done']);
        $recorder->end("tool:done:{$i}");
    }

    $recorder->end('run:done');

    expect($exporter->spans)->toHaveCount(401)
        ->and(array_unique(array_column(array_column($exporter->spans, 'call'), 'agent')))->toBe(['Done'])
        ->and($recorder->isOpen('run:open'))->toBeTrue();

    $recorder->end('run:open');

    expect($exporter->trees)->toHaveCount(2)
        ->and($exporter->trees[1])->toHaveCount(701);
});

it('a run sent at its end leaves other open runs, their open spans and the reset state alone', function () {
    $exporter = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);
    $resets = 0;
    $recorder->flushing(function () use (&$resets) {
        $resets++;
    });

    $recorder->start('run:open', 'invoke_agent', null, ['agent' => 'Open']);
    $recorder->start('step:open', 'chat', 'run:open', ['agent' => 'Open']);
    $recorder->start('step:done', 'chat', 'run:open', ['agent' => 'Open']);
    $recorder->end('step:done');

    for ($i = 0; $i < 3; $i++) {
        $recorder->start("run:{$i}", 'invoke_agent', null, ['agent' => 'Done']);
        $recorder->end("run:{$i}");
    }

    expect($exporter->spans)->toHaveCount(3)
        ->and(array_unique(array_column(array_column($exporter->spans, 'call'), 'agent')))->toBe(['Done'])
        ->and($resets)->toBe(0)
        ->and($recorder->isOpen('run:open'))->toBeTrue()
        ->and($recorder->isOpen('step:open'))->toBeTrue();

    $recorder->end('step:open');
    $recorder->end('run:open');
    $recorder->flush();

    $open = array_values(array_filter($exporter->spans, fn (array $span) => $span['call']['agent'] === 'Open'));

    expect($exporter->spans)->toHaveCount(6)
        ->and(array_column($open, 'status'))->toBe(['ok', 'ok', 'ok'])
        ->and($resets)->toBe(1);
});

it('a flush point sends the runs it closes as abandoned together, in one export', function () {
    $this->refreshApplicationWithConfig(lifecycleConfig());
    Http::fake();
    $recorder = app(Recorder::class);

    $recorder->start('run:a', 'invoke_agent', null, ['agent' => 'A']);
    $recorder->start('step:a', 'chat', 'run:a', []);
    $recorder->start('run:b', 'invoke_agent', null, ['agent' => 'B']);

    Http::assertNothingSent();

    app(FlushPoints::class)->flush();

    $spans = Otlp::spans();

    Http::assertSentCount(1);
    expect($spans)->toHaveCount(3)
        ->and(array_unique(array_column($spans, 'traceId')))->toHaveCount(2)
        ->and(array_map(fn (array $span) => Otlp::attributes($span)['laravel.ai.abandoned'] ?? false, $spans))->toBe([true, true, true]);
});

it('a run that failed over keeps its failover state while other runs are sent', function () {
    $exporter = $this->captureNeutralSpans();
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
    $exporter->spans = [];

    $failover = array_search(true, array_map(fn (object $event) => $event instanceof AgentFailedOver, $events), true);
    $dispatcher = app('events');
    $recorder = app(Recorder::class);

    foreach (array_slice($events, 0, $failover + 1) as $event) {
        $copy = clone $event;
        $copy->invocationId = 'kept';
        $dispatcher->dispatch($copy);
    }

    for ($i = 0; $i < 3; $i++) {
        $recorder->start("run:{$i}", 'invoke_agent', null, ['agent' => 'Done']);
        $recorder->end("run:{$i}");
    }

    $sent = count($exporter->spans);

    foreach (array_slice($events, $failover + 1) as $event) {
        $copy = clone $event;
        $copy->invocationId = 'kept';
        $dispatcher->dispatch($copy);
    }

    $recorder->flush();

    $kept = array_values(array_filter($exporter->spans, fn (array $span) => ($span['call']['agent'] ?? null) !== 'Done'));

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

it('a sub-agent run dropped at the cap that fails over stays dropped, its next attempt too', function () {
    $exporter = $this->captureNeutralSpans();
    config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test']]);

    // The events of one real run with a failover, replayed under the invocation id x.
    $events = [];

    Event::listen('Laravel\Ai\Events\*', function (string $name, array $payload) use (&$events) {
        $events[] = $payload[0];
    });

    TimeAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => $provider->name() === 'openai'
        ? throw RateLimitedException::forProvider('openai')
        : 'It is 12:00.');
    TimeAgent::make()->prompt('What time is it?', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b']);

    app('events')->forget('Laravel\Ai\Events\*');
    $exporter->trees = $exporter->spans = [];

    $failover = array_search(true, array_map(fn (object $event) => $event instanceof AgentFailedOver, $events), true);

    $replay = function (array $events) {
        foreach ($events as $event) {
            $copy = clone $event;
            $copy->invocationId = 'x';

            // A sub-agent: the prompt names the tool that called it.
            if ($copy instanceof PromptingAgent) {
                $prompt = $copy->prompt;
                $copy->prompt = new AgentPrompt($prompt->agent, $prompt->prompt, $prompt->attachments, $prompt->provider, $prompt->model,
                    invocationId: 'x', parentToolInvocationId: 't');
            }

            app('events')->dispatch($copy);
        }
    };

    // The parent run is at the cap when the tool that calls the sub-agent starts.
    $recorder = app(Recorder::class);
    $recorder->start('run:p', 'invoke_agent', null, ['agent' => 'Parent']);

    for ($i = 1; $i < Recorder::MAX_RUN_SPANS; $i++) {
        $recorder->start("tool:{$i}", 'execute_tool', 'run:p', ['tool' => 'Filler']);
        $recorder->end("tool:{$i}");
    }

    $recorder->start('tool:t', 'execute_tool', 'run:p', ['tool' => 'Dropped']);

    // Its first attempt fails over, then the next attempt runs.
    $replay(array_slice($events, 0, $failover + 1));

    expect($recorder->isDropped('run:x'))->toBeTrue();

    $replay(array_slice($events, $failover + 1));

    $recorder->end('tool:t');
    $recorder->end('run:p');

    // A run after it is recorded as usual.
    runTimeAgent();

    $ofX = array_filter($exporter->spans, fn (array $span) => ($span['call']['invocation_id'] ?? null) === 'x'
        || in_array($span['call']['model'] ?? null, ['gpt-a', 'claude-b'], true));

    expect($ofX)->toBe([])
        ->and($recorder->isDropped('run:x'))->toBeFalse()
        ->and($exporter->trees)->toHaveCount(2)
        ->and($exporter->trees[0])->toHaveCount(Recorder::MAX_RUN_SPANS)
        ->and($exporter->trees[1])->toHaveCount(4);
});
