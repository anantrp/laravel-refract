<?php

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Events\TracesDropped;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Stats;
use Anantrp\Refract\Tests\Support\MemoryCollector;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\Drain;
use Anantrp\Refract\Transport\DrainTraces;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * The queue drain: finished trees go into the collector, and a unique
 * DrainTraces job exports them from the app's workers, releasing them
 * with a backoff when the destination cannot take them now.
 */

beforeEach(function () {
    $this->environmentConfig = ['refract.drain' => 'queue'];
    $this->exporter = $this->captureNeutralSpans();
    $this->collector = new MemoryCollector;

    app()->instance(Collector::class, $this->collector);

    Diagnostics::reset();
    Log::swap($this->log = new WarningLog);
    Queue::fake();
});

/**
 * A run tree of one neutral span, named so a test can tell trees apart.
 *
 * @return list<array<string, mixed>>
 */
function drainTree(string $name): array
{
    return [[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => bin2hex(random_bytes(8)), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => ['agent' => $name], 'content' => [], 'context' => [], 'events' => [],
    ]];
}

/**
 * Run one drain job in this process, as a worker would.
 */
function drainNow(?DrainTraces $job = null): void
{
    ($job ?? new DrainTraces)->handle(app());
}

/**
 * Put the real queue back in place of the fake, on the given connection.
 */
function realQueue(string $connection): void
{
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');

    // Failed jobs are logged to the tests' database, where a test can count them.
    config(['queue.default' => $connection, 'queue.failed.database' => 'testing']);
}

/**
 * Get the waits of the drain jobs pushed so far, in order.
 *
 * @return list<int>
 */
function pushedDrains(): array
{
    $jobs = collect(Queue::pushedJobs())->flatten(1)->pluck('job')->filter(fn ($job) => $job instanceof DrainTraces);

    return $jobs->map(fn (DrainTraces $job) => $job->wait)->values()->all();
}

/**
 * Get the agent names of every tree exported with an ok result so far, in order.
 *
 * @return list<string>
 */
function drainedNames(): array
{
    return array_map(fn (array $tree) => $tree[0]['call']['agent'], test()->exporter->trees);
}

it('the queue drain stores each tree and dispatches one drain job, 5 s later on the refract queue', function () {
    app(Drain::class)->send([drainTree('one'), drainTree('two')]);

    expect($this->collector->size())->toBe(2)
        ->and($this->exporter->exports)->toBe(0);

    Queue::assertPushedOn('refract', DrainTraces::class);
    Queue::assertPushed(DrainTraces::class, fn (DrainTraces $job) => $job->delay === 5 && $job->afterCommit === false && $job->tries === 0 && $job->timeout === 60);
});

it('a drain job is dispatched once while one is waiting: later pushes are no-ops', function () {
    app(Drain::class)->send([drainTree('one')]);
    app(Drain::class)->send([drainTree('two')]);
    app(Drain::class)->send([drainTree('three')]);

    expect(pushedDrains())->toBe([5])
        ->and($this->collector->size())->toBe(3);
});

it('a drain exports every stored tree, acks them and dispatches nothing', function () {
    app(Drain::class)->send([drainTree('one'), drainTree('two'), drainTree('three')]);
    Queue::fake();

    drainNow();

    expect(drainedNames())->toBe(['one', 'two', 'three'])
        ->and($this->exporter->exports)->toBe(1)
        ->and($this->collector->size())->toBe(0)
        ->and(pushedDrains())->toBe([])
        ->and($this->log->warnings)->toBe([]);
});

it('a drain loops claim, export and ack until the store is empty', function () {
    foreach (range(1, 3) as $run) {
        $this->collector->push(drainTree("run {$run}"));
    }

    // Each claim takes one tree.
    $collector = new class extends MemoryCollector
    {
        public function claim(int $maxBytes, int $lease): array
        {
            return parent::claim(1, $lease);
        }
    };
    $collector->entries = $this->collector->entries;
    app()->instance(Collector::class, $collector);

    drainNow();

    expect($this->exporter->exports)->toBe(3)
        ->and(drainedNames())->toBe(['run 1', 'run 2', 'run 3'])
        ->and($collector->size())->toBe(0);
});

it('a rejected batch is acked: it is not tried again', function () {
    $this->collector->push(drainTree('one'));
    $this->exporter->results = [ExportResult::rejected()];

    drainNow();

    expect($this->collector->size())->toBe(0)
        ->and($this->collector->releases)->toBe([])
        ->and(pushedDrains())->toBe([]);
});

it('a retryable batch is released with a backoff, the drain stops, and the next drain is dispatched for then', function () {
    $this->collector->push(drainTree('one'));
    $this->collector->push(drainTree('two'));
    $this->exporter->results = [ExportResult::retryable()];

    drainNow();

    expect($this->collector->releases)->toBe([[[1, 2], 10]])
        ->and($this->collector->size())->toBe(2)
        ->and(pushedDrains())->toBe([10])
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('tried again in 10 seconds');

    $this->travel(10)->seconds();
    drainNow();

    expect(drainedNames())->toBe(['one', 'two'])
        ->and($this->collector->size())->toBe(0);
});

it('failed exports in a row back off 10, 60, 300 and 300 s, and a delivered batch starts over', function () {
    $this->collector->push(drainTree('one'));
    $this->exporter->results = array_fill(0, 4, ExportResult::retryable());

    foreach ([10, 60, 300, 300] as $wait) {
        drainNow();
        $this->travel($wait)->seconds();
    }

    drainNow();

    $this->collector->push(drainTree('two'));
    $this->exporter->results = [ExportResult::retryable()];

    drainNow();

    expect(array_column($this->collector->releases, 1))->toBe([10, 60, 300, 300, 10])
        ->and(drainedNames())->toBe(['one']);
});

it('a Retry-After longer than the backoff is waited for, up to 300 s; a shorter one never cuts the backoff', function (?int $retryAfter, int $wait) {
    $this->collector->push(drainTree('one'));
    $this->exporter->results = [ExportResult::retryable($retryAfter)];

    drainNow();

    expect($this->collector->releases[0][1])->toBe($wait)
        ->and(pushedDrains())->toBe([$wait]);
})->with([
    'none' => [null, 10],
    'shorter' => [3, 10],
    'longer' => [120, 120],
    'the cap' => [300, 300],
]);

it('a drain that starts during a backoff sends nothing and waits for the rest of it', function () {
    $this->collector->push(drainTree('one'));
    $this->exporter->results = [ExportResult::retryable(120)];

    drainNow();
    Queue::fake();

    // A push in the meantime dispatched another drain.
    $this->collector->push(drainTree('two'));
    $this->travel(30)->seconds();

    drainNow();

    expect($this->exporter->exports)->toBe(1)
        ->and(pushedDrains())->toBe([90]);

    $this->travel(90)->seconds();
    drainNow();

    expect(drainedNames())->toEqualCanonicalizing(['one', 'two']);
});

it('trees claimed by a crashed worker are exported once their lease runs out', function () {
    $this->collector->push(drainTree('one'));

    // The crashed worker's claim.
    $this->collector->claim(DrainTraces::CLAIM_BYTES, DrainTraces::LEASE);

    drainNow();

    expect($this->exporter->exports)->toBe(0)
        ->and(pushedDrains())->toBe([30]);

    $this->travel(DrainTraces::LEASE)->seconds();
    drainNow();

    expect(drainedNames())->toBe(['one'])
        ->and($this->collector->size())->toBe(0);
});

it('a drain that spends its time budget dispatches the next drain at once and stops', function () {
    $this->collector->push(drainTree('one'));

    drainNow(new class extends DrainTraces
    {
        protected const int BUDGET = 0;
    });

    expect($this->exporter->exports)->toBe(0)
        ->and(pushedDrains())->toBe([0]);
});

it('a drain gives each export what is left of its 45 s limit, so it ends within its 60 s timeout and a lease outlives it', function () {
    $this->collector->push(drainTree('one'));

    drainNow();

    $job = new DrainTraces;

    expect($this->exporter->limits)->toHaveCount(1)
        ->and($this->exporter->limits[0])->toBeGreaterThan(44)->toBeLessThanOrEqual(45)
        ->and($job->timeout)->toBeGreaterThan(45)
        ->and(DrainTraces::LEASE)->toBeGreaterThan($job->timeout);
});

it('an export cut short by the time limit acks the trees done, releases the rest at once and dispatches the next drain', function () {
    foreach (['one', 'two', 'three'] as $name) {
        $this->collector->push(drainTree($name));
    }

    $this->exporter->results = [ExportResult::unfinished(1)];

    drainNow();

    expect(drainedNames())->toBe(['one'])
        ->and($this->collector->releases)->toBe([[[2, 3], 0]])
        ->and($this->collector->size())->toBe(2)
        ->and(pushedDrains())->toBe([0])
        ->and($this->log->warnings)->toBe([]);

    drainNow();

    expect(drainedNames())->toBe(['one', 'two', 'three'])
        ->and($this->collector->size())->toBe(0);
});

it('an export cut part way through a tree acks that tree and stores only its unsent spans, so the next drain picks up from there', function () {
    foreach (['one', 'two', 'three'] as $name) {
        $this->collector->push(drainTree($name));
    }

    $this->exporter->results = [ExportResult::unfinished(1, drainTree('two, rest'))];

    drainNow();

    expect(drainedNames())->toBe(['one'])
        ->and($this->collector->releases)->toBe([[[3], 0]])
        ->and($this->collector->size())->toBe(2)
        ->and(pushedDrains())->toBe([0]);

    drainNow();

    expect(drainedNames())->toBe(['one', 'three', 'two, rest'])
        ->and($this->collector->size())->toBe(0);
});

it('when the unsent spans of a cut tree cannot be stored, the whole tree goes back to be sent again', function () {
    foreach (['one', 'two'] as $name) {
        $this->collector->push(drainTree($name));
    }

    $this->collector->throwOnPush = true;
    $this->exporter->results = [ExportResult::unfinished(1, drainTree('two, rest'))];

    drainNow();

    expect($this->collector->releases)->toBe([[[2], 0]])
        ->and($this->collector->size())->toBe(1)
        ->and($this->log->warnings)->toHaveCount(1);
});

it('when a full store drops the unsent spans of a cut tree, the whole tree goes back to be sent again', function () {
    $this->collector = new MemoryCollector(maxTrees: 2);
    app()->instance(Collector::class, $this->collector);

    foreach (['one', 'two'] as $name) {
        $this->collector->push(drainTree($name));
    }

    $this->exporter->results = [ExportResult::unfinished(1, drainTree('two, rest'))];

    drainNow();

    expect($this->collector->releases)->toBe([[[2], 0]])
        ->and($this->collector->size())->toBe(1)
        ->and($this->collector->entries[2]['tree'][0]['call']['agent'])->toBe('two');
});

it('a drain killed by its worker is never failed: it is attempted again, however many times', function () {
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations');
    realQueue('database');

    DrainTraces::dispatch(0);

    // A worker died with the drain reserved: its retry_after ran out, twice.
    DB::table('jobs')->update(['attempts' => 2, 'reserved_at' => now()->subMinutes(5)->getTimestamp()]);

    Artisan::call('queue:work', ['--once' => true, '--queue' => 'refract', '--sleep' => 0, '--tries' => 1]);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('a drain that cannot make the exporter dispatches nothing when the store is empty', function () {
    app()->bind(Exporter::class, fn () => throw new RuntimeException('no destination'));

    drainNow();

    expect(pushedDrains())->toBe([])
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('could not make the exporter');
});

it('a drain that cannot make the exporter tries again with the backoff while trees are stored', function () {
    app()->bind(Exporter::class, fn () => throw new RuntimeException('no destination'));
    $this->collector->push(drainTree('one'));

    foreach ([10, 60, 300] as $wait) {
        // As a worker does when the drain starts.
        app(Repository::class)->lock(UniqueLock::getKey(new DrainTraces))->forceRelease();

        drainNow();
        $this->travel($wait)->seconds();
    }

    expect(pushedDrains())->toBe([10, 60, 300])
        ->and($this->collector->size())->toBe(1);
});

it('a drain on an empty store sends nothing and dispatches nothing', function () {
    drainNow();

    expect($this->exporter->exports)->toBe(0)
        ->and(pushedDrains())->toBe([]);
});

it('a drain never throws: a store that fails is one warning, without the message', function () {
    $this->collector->push(drainTree('one'));
    $this->collector->throws = true;

    drainNow();

    expect($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain(LogicException::class)
        ->and($this->log->warnings[0])->not->toContain('SECRET')
        ->and(pushedDrains())->toBe([DrainTraces::LEASE]);
});

it('an exporter that throws drops the batch with one warning, so it is never tried again', function () {
    $this->collector->push(drainTree('one'));
    $this->exporter->throws = true;

    drainNow();

    expect($this->collector->size())->toBe(0)
        ->and(pushedDrains())->toBe([])
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('dropped')
        ->and($this->log->warnings[0])->not->toContain('SECRET');
});

it('a store that fails on push drops the trees with one warning and dispatches nothing', function () {
    $this->collector->throws = true;

    app(Drain::class)->send([drainTree('one')]);

    expect(pushedDrains())->toBe([])
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('could not be stored')
        ->and($this->log->warnings[0])->not->toContain('SECRET');
});

it('a push that fails drops only its tree: the rest are stored and the drain is dispatched, with one warning', function () {
    $collector = new class extends MemoryCollector
    {
        public function push(array $tree): bool
        {
            if ($tree[0]['call']['agent'] !== 'kept') {
                throw new LogicException('Refract bug in the collector, SECRET');
            }

            return parent::push($tree);
        }
    };
    app()->instance(Collector::class, $collector);

    app(Drain::class)->send([drainTree('lost'), drainTree('kept'), drainTree('lost too'), drainTree('kept')]);

    expect($collector->size())->toBe(2)
        ->and(pushedDrains())->toBe([5])
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('could not be stored');
});

it('a drain job that cannot be dispatched is one warning, and the trees stay stored', function () {
    app()->instance('cache', new class
    {
        public function __call(string $method, array $arguments): never
        {
            throw new RuntimeException('cache down');
        }
    });
    app()->forgetInstance(Repository::class);
    app()->bind(Repository::class, fn () => throw new RuntimeException('cache down'));

    app(Drain::class)->send([drainTree('one')]);

    expect($this->collector->size())->toBe(1)
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('could not be dispatched');
});

it('with the queue drain only the drain job exports, and it waits at most 15 s for the destination', function () {
    $this->environmentConfig = [
        'refract.drain' => 'queue',
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
    ];
    $this->memoryExporter = null;
    $this->refreshApplication();
    app()->instance(Collector::class, $collector = new MemoryCollector);
    Queue::fake();

    $timeouts = [];
    Http::fake(function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response('', 200);
    });

    app(Drain::class)->send([drainTree('one')]);

    Http::assertNothingSent();

    drainNow();

    expect($timeouts)->toBe([15])
        ->and($collector->size())->toBe(0);
});

it('on a sync queue connection the drain runs at once and never dispatches itself', function () {
    realQueue('sync');
    $this->exporter->results = [ExportResult::retryable()];

    app(Drain::class)->send([drainTree('one')]);

    expect($this->exporter->exports)->toBe(1)
        ->and($this->collector->releases)->toBe([[[1], 10]])
        ->and($this->log->warnings)->toHaveCount(2)
        ->and($this->log->warnings[1])->toContain('sync queue connection');
});

it('on a database queue a worker runs the drain once it is due, and nothing reaches failed_jobs', function () {
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations');
    realQueue('database');

    app(Drain::class)->send([drainTree('one')]);
    $this->exporter->results = [ExportResult::retryable()];

    expect(DB::table('jobs')->where('queue', 'refract')->count())->toBe(1);

    Artisan::call('queue:work', ['--once' => true, '--queue' => 'refract', '--sleep' => 0]);

    expect($this->exporter->exports)->toBe(0);

    $this->travel(5)->seconds();
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'refract', '--sleep' => 0]);

    // The worker took the unique lock off when the drain started, so the drain could dispatch its retry.
    expect($this->exporter->exports)->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'refract')->count())->toBe(1);

    $this->travel(10)->seconds();
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'refract', '--sleep' => 0]);

    expect(drainedNames())->toBe(['one'])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('a 503 on the second request acks the trees the first one delivered', function () {
    // The real exporter, not the memory one, so the trees go out in requests.
    $this->memoryExporter = null;
    bootExport(['refract.drain' => 'queue']);
    app()->instance(Collector::class, $collector = new MemoryCollector);
    Queue::fake();

    // Each tree is about 2.5 MB of OTLP JSON, so two do not fit one 4 MB request; it is text, so both fit one claim, gzipped.
    $text = fn (string $traceId) => str_repeat("The release ships on Friday {$traceId}. ", 40_000);
    $tree = fn (string $traceId) => [[
        ...drainTree($traceId)[0],
        'trace_id' => $traceId,
        'content' => ['output' => [['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => $text($traceId)]]]]],
    ]];
    $first = str_repeat('1', 32);
    $second = str_repeat('2', 32);

    $collector->push($tree($first));
    $collector->push($tree($second));

    Http::fake(['*' => Http::sequence()->push('', 200)->push('', 503)->whenEmpty(Http::response('', 200))]);

    drainNow();

    expect($collector->size())->toBe(1);

    $this->travel(11)->seconds();
    drainNow();

    $bodies = Http::recorded()->map(fn (array $pair) => Otlp::body($pair[0]))->values()->all();

    expect($bodies)->toHaveCount(3)
        ->and($bodies[2])->toContain($second)->not->toContain($first)
        ->and($collector->size())->toBe(0);
});

/**
 * Use the real exporter on the queue drain (the test sets memoryExporter to null first), with a destination that answers 503 to any request carrying the given trace id, or to every request while it is down.
 */
function poisonDestination(string $poison, bool &$down = false): MemoryCollector
{
    bootExport(['refract.drain' => 'queue']);
    app()->instance(Collector::class, $collector = new MemoryCollector);
    Queue::fake();

    Http::fake(function (Request $request) use ($poison, &$down) {
        return $down || str_contains(Otlp::body($request), $poison) ? Http::response('', 503) : Http::response('', 200);
    });

    return $collector;
}

/**
 * Get the trace ids each request carried, in order.
 *
 * @return list<list<string>>
 */
function sentTraceIds(): array
{
    return Http::recorded()->map(function (array $pair) {
        preg_match_all('/"traceId":"([0-9a-f]{32})"/', Otlp::body($pair[0]), $ids);

        return array_values(array_unique($ids[1]));
    })->values()->all();
}

/**
 * A tree of one span in the given trace.
 *
 * @return list<array<string, mixed>>
 */
function traceTree(string $traceId): array
{
    return [[...drainTree($traceId)[0], 'trace_id' => $traceId]];
}

it('the other trees in its batch are delivered once it is sent alone', function () {
    $poison = str_repeat('9', 32);
    $this->memoryExporter = null;
    $collector = poisonDestination($poison);

    $collector->push(traceTree(str_repeat('1', 32)));
    $collector->push(traceTree($poison));
    $collector->push(traceTree(str_repeat('2', 32)));

    // Three failed batches, each with the tree in it.
    foreach (range(1, 3) as $drain) {
        drainNow();
        $this->travel(301)->seconds();
    }

    expect($collector->size())->toBe(3);

    drainNow();

    expect($collector->size())->toBe(1)
        ->and(array_slice(sentTraceIds(), 3))->each->toHaveCount(1);
});

it('a tree that keeps failing is sent alone after 3 attempts and dropped after 10', function () {
    $poison = str_repeat('9', 32);
    $this->memoryExporter = null;
    $collector = poisonDestination($poison);
    Event::fake([TracesDropped::class]);

    $collector->push(traceTree($poison));

    // A new run each time, so the destination is seen to answer.
    foreach (range(1, 10) as $drain) {
        $collector->push(traceTree(str_pad((string) $drain, 32, '0', STR_PAD_LEFT)));
        drainNow();
        $this->travel(301)->seconds();
    }

    $alone = array_filter(sentTraceIds(), fn (array $ids) => in_array($poison, $ids, true));

    expect($collector->size())->toBe(0)
        ->and(array_slice(array_values($alone), 3))->each->toBe([$poison])
        ->and(Stats::read()['dropped'])->toBe(['poison' => 1]);

    Event::assertDispatched(TracesDropped::class, fn ($e) => $e->reason === 'poison' && $e->count === 1);
});

it('a long outage drops nothing: a tree is dropped as poison only while the destination answers others', function () {
    $down = true;
    $this->memoryExporter = null;
    $collector = poisonDestination(str_repeat('9', 32), $down);

    foreach (range(1, 3) as $tree) {
        $collector->push(traceTree(str_repeat((string) $tree, 32)));
    }

    foreach (range(1, 20) as $drain) {
        drainNow();
        $this->travel(301)->seconds();
    }

    expect($collector->size())->toBe(3)
        ->and(Stats::read()['dropped'])->toBe([]);

    $down = false;
    drainNow();

    expect($collector->size())->toBe(0);
});
