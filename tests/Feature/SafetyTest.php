<?php

use Anantrp\Refract\Capture\FlushPoints;
use Anantrp\Refract\Capture\RecordAgentRuns;
use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Export\GenAiTranslator;
use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\Export\OtlpJson;
use Anantrp\Refract\Export\Platform;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Ai\CountingAgent;
use Anantrp\Refract\Tests\Support\Ai\CountingSupervisor;
use Anantrp\Refract\Tests\Support\Env;
use Anantrp\Refract\Tests\Support\FlakyTransport;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\RefractStack;
use Anantrp\Refract\Tests\Support\ThrowingRecorder;
use Anantrp\Refract\Tests\Support\WarningLog;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\RateLimitedException;
use Workbench\App\Ai\Agents\ChatAgent;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Jobs\RunAgent;

/*
 * Rule 1: never break the app. Rule 7: one warning path. Rule 10: no extra calls.
 */

/**
 * The config of a working Langfuse destination with the sync transport.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function safetyConfig(array $config = []): array
{
    return [
        'refract.transport' => 'sync',
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test'],
        ...$config,
    ];
}

/**
 * Boot the app with the given config, fake HTTP and record warnings.
 *
 * @param  array<string, mixed>  $config
 */
function bootSafety(array $config = []): WarningLog
{
    test()->refreshApplicationWithConfig(safetyConfig($config));

    Http::fake();
    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    return $log;
}

/**
 * Run the agent runs Refract listens to: a prompt with a tool, a stream, a failover and a failure.
 */
function runEveryKindOfRun(): void
{
    TimeAgent::fakeTwoSteps();
    expect(TimeAgent::make()->prompt('What time is it?')->text)->toBe('It is 12:00.');

    TimeAgent::fakeTwoSteps();
    $text = '';

    foreach (TimeAgent::make()->stream('What time is it?') as $event) {
        $text .= $event->delta ?? '';
    }

    expect($text)->toBe('It is 12:00.');

    TimeAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => $provider->name() === 'openai'
        ? throw RateLimitedException::forProvider('openai')
        : 'It is 12:00.');

    expect(TimeAgent::make()->prompt('What time is it?', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b'])->text)->toBe('It is 12:00.');
}

/**
 * Count the hooks Refract added: event listeners and terminating callbacks bound to a Refract object.
 */
function refractHooks(): int
{
    $isRefract = function (mixed $listener): bool {
        if (! $listener instanceof Closure) {
            return false;
        }

        $reflection = new ReflectionFunction($listener);
        $bound = $reflection->getClosureThis();

        if ($bound === null || $bound instanceof Closure) {
            // A listener the dispatcher wrapped: look at the closure it holds.
            foreach ($reflection->getClosureUsedVariables() as $used) {
                if ($used instanceof Closure && ($inner = (new ReflectionFunction($used))->getClosureThis()) !== null) {
                    return str_starts_with($inner::class, 'Anantrp\\Refract\\');
                }
            }

            return false;
        }

        return str_starts_with($bound::class, 'Anantrp\\Refract\\');
    };

    $hooks = 0;

    foreach (app('events')->getRawListeners() as $listeners) {
        foreach ($listeners as $listener) {
            $hooks += $isRefract($listener) ? 1 : 0;
        }
    }

    $callbacks = (new ReflectionProperty(app(), 'terminatingCallbacks'))->getValue(app());

    foreach ($callbacks as $callback) {
        $hooks += $isRefract($callback) ? 1 : 0;
    }

    return $hooks;
}

afterEach(function () {
    Env::restore();
    CountingAgent::$sdkCalls = [];
    CountingAgent::$refractCalls = [];
});

it('S1: a listener that throws lets every kind of run continue, with one warning that names no value', function () {
    $this->extenders[Recorder::class] = fn (Recorder $recorder, Application $app) => new ThrowingRecorder($app);
    $log = bootSafety();

    runEveryKindOfRun();

    app(FlushPoints::class)->flush();

    expect(app(Recorder::class))->toBeInstanceOf(ThrowingRecorder::class)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(LogicException::class)
        ->and($log->warnings[0])->not->toContain('SECRET');
});

it('S1: the app gets its own exception unchanged when the listener for the failure throws', function () {
    $this->extenders[Recorder::class] = fn (Recorder $recorder, Application $app) => new ThrowingRecorder($app);
    $log = bootSafety();

    $error = new RuntimeException('The agent failed.');
    TimeAgent::fake(fn () => throw $error);

    $caught = null;

    try {
        TimeAgent::make()->prompt('What time is it?');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBe($error)
        ->and($log->warnings)->toHaveCount(1);
});

it('S1: a run whose context cannot be read is recorded without its context, with one warning', function () {
    bootSafety();
    $memory = $this->captureNeutralSpans();
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations');
    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    $agent = new class extends ChatAgent
    {
        public function currentConversation(): ?string
        {
            if (RefractStack::calledByRefract()) {
                throw new LogicException('Context bug, SECRET');
            }

            return parent::currentConversation();
        }
    };

    $agent::fake(['Hello.']);

    expect($agent->prompt('Hello')->text)->toBe('Hello.');

    app(Recorder::class)->flush();

    expect(array_column($memory->spans, 'kind'))->toBe(['chat', 'invoke_agent'])
        ->and($memory->spans[1]['context'])->toBe([])
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(LogicException::class)
        ->and($log->warnings[0])->not->toContain('SECRET');
});

it('S2: a log that cannot be written throws nothing to the app', function () {
    $this->extenders[Recorder::class] = fn (Recorder $recorder, Application $app) => new ThrowingRecorder($app);
    bootSafety();

    Log::swap(new class
    {
        /**
         * @param  array<int, mixed>  $arguments
         */
        public function __call(string $method, array $arguments): never
        {
            throw new RuntimeException('The log disk is full.');
        }
    });

    runEveryKindOfRun();

    Diagnostics::warn('test.log', 'A warning.');
    app(FlushPoints::class)->flush();

    expect(true)->toBeTrue();
});

/**
 * Use the database queue on the test database, with an empty jobs table.
 */
function safetyQueue(): void
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

it('S3: a flush hook whose transport throws still clears the buffer, and the app is unaffected', function (string $hook) {
    $transport = new FlakyTransport;
    $this->extenders[Transport::class] = fn () => $transport;

    if ($hook === 'web terminating') {
        Env::set(['APP_RUNNING_IN_CONSOLE' => 'false']);
    }

    $log = bootSafety();

    match ($hook) {
        'web terminating' => (function () {
            Route::get('/agent', function () {
                TimeAgent::fakeTwoSteps();
                TimeAgent::make()->prompt('What time is it?');

                return 'ok';
            });

            $kernel = app(Kernel::class);
            $request = Request::create('/agent');
            $response = $kernel->handle($request);

            expect($response->getStatusCode())->toBe(200);

            $kernel->terminate($request, $response);
        })(),
        'job end' => (function () {
            safetyQueue();
            Queue::push(new RunAgent);
            app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
        })(),
        'command end' => (function () {
            app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
            Artisan::command('refract-test:agent', function () {
                TimeAgent::fakeTwoSteps();
                TimeAgent::make()->prompt('What time is it?');
            });

            expect(Artisan::call('refract-test:agent'))->toBe(0);
        })(),
    };

    $transport->throws = false;
    app(Recorder::class)->flush();

    expect($transport->spans)->toBe([])
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(LogicException::class)
        ->and($log->warnings[0])->not->toContain('SECRET');
})->with(['web terminating', 'job end', 'command end']);

it('S3: a flush that fails while building the batch, or a reset that throws, still clears the buffer', function () {
    $transport = new FlakyTransport;
    $transport->throws = false;
    $this->extenders[Transport::class] = fn () => $transport;
    $this->extenders[Recorder::class] = fn (Recorder $recorder, Application $app) => new class($app) extends Recorder
    {
        public bool $broken = true;

        protected function inheritContext(array $spans): array
        {
            if ($this->broken) {
                throw new LogicException('Refract bug in the flush, SECRET');
            }

            return parent::inheritContext($spans);
        }
    };

    $log = bootSafety();
    $recorder = app(Recorder::class);
    $recorder->flushing(fn () => throw new RuntimeException('Refract bug in a reset, SECRET'));

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');

    app(FlushPoints::class)->flush();

    $recorder->broken = false;
    $recorder->flush();

    expect($transport->spans)->toBe([])
        ->and($log->warnings)->toHaveCount(2)
        ->and(implode("\n", $log->warnings))->toContain(LogicException::class)
        ->and(implode("\n", $log->warnings))->toContain(RuntimeException::class)
        ->and(implode("\n", $log->warnings))->not->toContain('SECRET');
});

it('S4: 11 different internal errors give 10 warnings, then silence', function () {
    bootSafety();
    Log::swap($log = new WarningLog);

    foreach (range(1, 11) as $error) {
        Diagnostics::warn("error.{$error}", "Internal error {$error}.");
        Diagnostics::warn("error.{$error}", "Internal error {$error} again.");
    }

    expect($log->warnings)->toHaveCount(10)
        ->and($log->warnings[9])->toContain('Internal error 10.')
        ->and(implode("\n", $log->warnings))->not->toContain('again');
});

it('S5: instructions(), tools() and name() are called only by the SDK, never by Refract', function () {
    bootSafety(['refract.capture.content' => true]);

    CountingAgent::fakeSteps();
    CountingAgent::make()->prompt('What time is it?');

    CountingAgent::fakeSteps();

    foreach (CountingAgent::make()->stream('What time is it?') as $event) {
        //
    }

    CountingAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => $provider->name() === 'openai'
        ? throw RateLimitedException::forProvider('openai')
        : 'It is 12:00.');
    CountingAgent::make()->prompt('What time is it?', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b']);

    // As a sub-agent, the SDK needs its name() for the tool.
    CountingSupervisor::fakeSteps();
    CountingSupervisor::make()->prompt('What time is it?');

    app(FlushPoints::class)->flush();

    $runs = collect(Otlp::spans())->map(Otlp::attributes(...))
        ->filter(fn (array $attributes) => ($attributes['laravel.ai.agent.class'] ?? null) === CountingAgent::class)
        ->values();

    // Run spans never call name(). The execute_tool span of the one sub-agent call resolves its
    // tool name the SDK's way (ToolNameResolver), which runs name() once: open question in log.md.
    expect(CountingAgent::$refractCalls)->toBe(['name' => 1])
        ->and(CountingAgent::$sdkCalls['instructions'] ?? 0)->toBeGreaterThan(0)
        ->and(CountingAgent::$sdkCalls['tools'] ?? 0)->toBeGreaterThan(0)
        ->and(CountingAgent::$sdkCalls['name'] ?? 0)->toBeGreaterThan(0)
        ->and(count(Http::recorded()))->toBe(1)
        // A top-level run is named by its class; a sub-agent run by the tool the SDK called it as.
        ->and($runs->map(fn (array $attributes) => $attributes['gen_ai.agent.name'])->all())->toBe(['CountingAgent', 'CountingAgent', 'CountingAgent', 'counting_agent']);
});

it('S6: with Refract disabled no listener or flush hook is registered', function (bool $enabled) {
    bootSafety(['refract.enabled' => $enabled]);

    $hooks = refractHooks();

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');

    if ($enabled) {
        expect($hooks)->toBeGreaterThan(0)
            ->and(app()->resolved(RecordAgentRuns::class))->toBeTrue();
    } else {
        expect($hooks)->toBe(0)
            ->and(app()->resolved(RecordAgentRuns::class))->toBeFalse()
            ->and(app()->resolved(FlushPoints::class))->toBeFalse()
            ->and(app()->resolved(Recorder::class))->toBeFalse();
    }
})->with(['disabled' => false, 'enabled (control)' => true]);

it('S7: 2,000 runs in one worker keep memory flat', function () {
    bootSafety(['refract.transport' => 'null', 'refract.capture.content' => true]);

    // The events of one real run with a failover, replayed under new invocation ids,
    // so the SDK's own fakes (which keep every prompt) do not grow memory here.
    $events = [];

    Event::listen('Laravel\Ai\Events\*', function (string $name, array $payload) use (&$events) {
        $events[] = $payload[0];
    });

    TimeAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => match (true) {
        $provider->name() === 'openai' => throw RateLimitedException::forProvider('openai'),
        default => 'It is 12:00.',
    });
    TimeAgent::make()->prompt('What time is it?', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b']);

    app('events')->forget('Laravel\Ai\Events\*');

    expect(count($events))->toBeGreaterThanOrEqual(8);

    $failover = array_values(array_filter($events, fn (object $event) => $event instanceof AgentFailedOver))[0];
    $flushPoints = app(FlushPoints::class);
    $dispatcher = app('events');

    $run = function (int $from, int $to) use ($events, $failover, $flushPoints, $dispatcher) {
        for ($i = $from; $i < $to; $i++) {
            // Long ids make any state kept per run show up in memory.
            $id = str_pad((string) $i, 2_048, '-');

            foreach ($events as $event) {
                $copy = clone $event;
                $copy->invocationId = $id;
                $dispatcher->dispatch($copy);
            }

            // A failover with no attempt after it (the next provider could not be made).
            $lost = clone $failover;
            $lost->invocationId = $id.'-lost';
            $dispatcher->dispatch($lost);

            $flushPoints->flush();
        }
    };

    $run(0, 200);
    gc_collect_cycles();
    $before = memory_get_usage();

    $run(200, 2_000);
    gc_collect_cycles();
    $grown = memory_get_usage() - $before;

    // 1,800 runs that each kept over 2 KB would add over 3.6 MB.
    expect($grown)->toBeLessThan(256 * 1024);
});

/**
 * One neutral run span with the given call data.
 *
 * @param  array<string, mixed>  $call
 * @return list<array<string, mixed>>
 */
function safetyBatch(array $call = []): array
{
    return [[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => ['agent' => 'TimeAgent', ...$call], 'content' => [], 'context' => [], 'events' => [],
    ]];
}

/**
 * An exporter that throws, as a bug in Refract might.
 */
function throwingExporter(): Exporter
{
    return new class implements Exporter
    {
        public function export(array $spans): ExportResult
        {
            throw new LogicException('Refract bug in the exporter, SECRET');
        }
    };
}

it('S1: an exporter that throws on the sync transport drops the batch with one warning', function () {
    $this->extenders[Exporter::class] = fn () => throwingExporter();
    $log = bootSafety();

    app(Transport::class)->send(safetyBatch());

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(LogicException::class)
        ->and($log->warnings[0])->toContain('dropped')
        ->and($log->warnings[0])->not->toContain('SECRET');
});

it('S1: an exporter that throws in the queue job drops the batch with one warning, not reported as a failed job', function () {
    $this->extenders[Exporter::class] = fn () => throwingExporter();
    $log = bootSafety(['refract.transport' => 'queue']);
    safetyQueue();
    Exceptions::fake();

    $failed = 0;
    Event::listen(JobFailed::class, function () use (&$failed) {
        $failed++;
    });

    app(Transport::class)->send(safetyBatch());
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));

    Exceptions::assertNothingReported();
    expect($failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(LogicException::class)
        ->and($log->warnings[0])->not->toContain('SECRET');
});

it('S1: a batch the queue transport cannot encode is exported in this process with one warning', function () {
    $log = bootSafety(['refract.transport' => 'queue']);
    safetyQueue();

    app(Transport::class)->send(safetyBatch(['broken' => new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new LogicException('Refract bug in the encoder, SECRET');
        }
    }]));

    Http::assertSentCount(1);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(LogicException::class)
        ->and($log->warnings[0])->not->toContain('SECRET');
});

it('S1: the exporter guards translating, preparing and encoding, not only the HTTP call', function () {
    $log = bootSafety();

    $platform = new class implements Platform
    {
        public static function fromConfig(string $key): ?static
        {
            return null;
        }

        public function endpoint(): string
        {
            return 'https://otlp.test/v1/traces';
        }

        public function headers(): array
        {
            return [];
        }

        public function resource(array $attributes): array
        {
            return $attributes;
        }

        public function prepare(array $spans): array
        {
            throw new LogicException('Refract bug in the platform, SECRET');
        }
    };

    $exporter = new HttpExporter($platform, new GenAiTranslator, new OtlpJson, 'testing', 'app');

    expect($exporter->export(safetyBatch()))->toBe(ExportResult::Rejected)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(LogicException::class)
        ->and($log->warnings[0])->not->toContain('SECRET');
    Http::assertNothingSent();
});
