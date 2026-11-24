<?php

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Stats;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\Collectors\TreePayload;
use Anantrp\Refract\Transport\Drain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Helpers shared by the test files: the run trees the collector tests
 * store, the behaviour every stored collector shares, and booting an
 * export against a faked destination.
 */

/**
 * A run tree of one neutral span, named so a test can tell trees apart.
 *
 * @return list<array<string, mixed>>
 */
function storedTree(string $name, string $text = ''): array
{
    return [[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => bin2hex(random_bytes(8)), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => ['agent' => $name], 'content' => $text === '' ? [] : ['output' => $text], 'context' => [], 'events' => [],
    ]];
}

/**
 * Get the agent names of the given claimed trees, in order.
 *
 * @param  array<array-key, list<array<string, mixed>>>  $trees
 * @return list<string>
 */
function storedNames(array $trees): array
{
    return array_values(array_map(fn (array $tree) => $tree[0]['call']['agent'], $trees));
}

/**
 * Get the bytes the given tree takes in a store.
 *
 * @param  list<array<string, mixed>>  $tree
 */
function storedBytes(array $tree): int
{
    return strlen((string) TreePayload::encode($tree));
}

/**
 * Get the bytes of the given tree's JSON, as a claim counts them.
 *
 * @param  list<array<string, mixed>>  $tree
 */
function jsonBytes(array $tree): int
{
    return strlen((string) json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR));
}

/**
 * Define the tests every stored collector passes, in the calling test file.
 *
 * The given closure makes an empty collector, capped at the given bytes.
 *
 * @param  Closure(int): Collector  $make
 */
function itBehavesLikeAStoredCollector(Closure $make): void
{
    it('counts failed attempts per tree: a failed release adds one, a plain release none, and a tree that moves keeps its count', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        $collector->push(storedTree('one'));
        $collector->push(storedTree('two'));

        $ids = array_keys($collector->claim(4_000_000, 60));

        expect(array_values($collector->attempts($ids)))->toBe([0, 0]);

        $collector->release([$ids[0]], 0, failed: true);
        $collector->release([$ids[1]], 0);

        $again = $collector->claim(4_000_000, 60);
        expect(array_combine(storedNames($again), array_values($collector->attempts(array_keys($again)))))->toBe(['one' => 1, 'two' => 0]);

        $collector->release(array_keys($again), 0, failed: true);
        $third = $collector->claim(4_000_000, 60);

        expect(array_combine(storedNames($third), array_values($collector->attempts(array_keys($third)))))->toBe(['one' => 2, 'two' => 1]);

        $collector->ack(array_keys($third));

        expect($collector->attempts(array_keys($third)))->toBe([]);
    });

    it('reports the most attempts of a stored tree', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        $collector->push(storedTree('one'));
        expect($collector->stats()['max_attempts'])->toBe(0);

        $collector->release(array_keys($collector->claim(4_000_000, 60)), 0, failed: true);

        expect($collector->stats()['max_attempts'])->toBe(1);
    });

    it('claims pushed trees oldest first, keyed by id, and acked trees leave the store', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        $collector->push(storedTree('one'));
        $collector->push(storedTree('two'));

        expect($collector->size())->toBe(2);

        $claimed = $collector->claim(4_000_000, 60);

        expect(storedNames($claimed))->toBe(['one', 'two'])
            ->and($collector->claim(4_000_000, 60))->toBe([])
            ->and($collector->size())->toBe(2);

        $collector->ack(array_keys($claimed));

        expect($collector->size())->toBe(0)
            ->and($collector->claim(4_000_000, 60))->toBe([]);
    });

    it('a claim adds trees until their JSON reaches the bytes, and the rest wait for the next claim', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        foreach (['one', 'two', 'three'] as $name) {
            $collector->push(storedTree($name, bin2hex(random_bytes(500))));
        }

        $limit = intdiv(3 * jsonBytes(storedTree('one', bin2hex(random_bytes(500)))), 2);

        // The second tree takes the claim past one and a half trees.
        expect(storedNames($collector->claim($limit, 60)))->toBe(['one', 'two'])
            ->and(storedNames($collector->claim($limit, 60)))->toBe(['three']);
    });

    it('a claim of trees that compress well still stops at the bytes of their JSON, and the rest wait for the next claim', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        foreach (['one', 'two', 'three', 'four', 'five'] as $name) {
            // About 100 KB of JSON that stores in well under 1 KB.
            $collector->push(storedTree($name, str_repeat('The release ships on Friday. ', 3_500)));
        }

        $limit = intdiv(5 * jsonBytes(storedTree('one', str_repeat('The release ships on Friday. ', 3_500))), 2);

        expect(storedNames($collector->claim($limit, 60)))->toBe(['one', 'two', 'three'])
            ->and(storedNames($collector->claim($limit, 60)))->toBe(['four', 'five'])
            ->and($collector->claim($limit, 60))->toBe([]);
    });

    it('a tree whose lease ran out is claimed again, as a crashed worker would leave it', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        $collector->push(storedTree('one'));

        $first = $collector->claim(4_000_000, 60);

        expect($collector->claim(4_000_000, 60))->toBe([]);

        // A lease of 0 seconds has run out at once.
        $again = $collector->claim(4_000_000, 0);

        expect(array_keys($again))->toBe(array_keys($first))
            ->and(storedNames($again))->toBe(['one']);
    });

    it('a released tree is claimed again only after its delay', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        $collector->push(storedTree('one'));
        $collector->release(array_keys($collector->claim(4_000_000, 60)), 1);

        expect($collector->claim(4_000_000, 0))->toBe([])
            ->and($collector->size())->toBe(1);

        usleep(1_100_000);

        expect(storedNames($collector->claim(4_000_000, 60)))->toBe(['one'])
            ->and($collector->size())->toBe(1);
    });

    it('a tree released with no delay is claimed again at once, and a long delay keeps it out', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        $collector->push(storedTree('now'));
        $collector->push(storedTree('later'));

        $ids = array_keys($collector->claim(4_000_000, 60));

        $collector->release([$ids[0]], 0);
        $collector->release([$ids[1]], 300);

        expect(storedNames($collector->claim(4_000_000, 60)))->toBe(['now'])
            ->and($collector->size())->toBe(2);
    });

    it('at the cap a new tree is dropped and counted with one warning, and the stored trees are kept', function () use ($make) {
        $trees = array_map(fn (string $name) => storedTree($name, str_repeat('x', 1_000)), ['one', 'two', 'three', 'four']);

        // Sized from the trees themselves: random span ids change the gzipped size by a few bytes.
        $collector = $make(storedBytes($trees[0]) + storedBytes($trees[1]) + 10);

        foreach ($trees as $tree) {
            $collector->push($tree);
        }

        expect($collector->size())->toBe(2)
            ->and(Stats::read()['dropped']['full'] ?? 0)->toBe(2)
            ->and($this->log->warnings)->toHaveCount(1)
            ->and($this->log->warnings[0])->toContain('full');

        $claimed = $collector->claim(4_000_000, 60);

        expect(storedNames($claimed))->toBe(['one', 'two']);

        // Acked trees free their bytes: the store takes new trees again.
        $collector->ack(array_keys($claimed));
        $collector->push(storedTree('five', str_repeat('x', 1_000)));

        expect(storedNames($collector->claim(4_000_000, 60)))->toBe(['five']);
    });

    it('released trees still count against the cap until they are acked', function () use ($make) {
        $one = storedTree('one', str_repeat('x', 1_000));
        $collector = $make(storedBytes($one) + 10);

        $collector->push($one);
        $collector->release(array_keys($collector->claim(4_000_000, 60)), 300);
        $collector->push(storedTree('two', str_repeat('x', 1_000)));

        expect($collector->size())->toBe(1)
            ->and(Stats::read()['dropped']['full'] ?? 0)->toBe(1);
    });

    it('a stored tree survives the trip: invalid UTF-8 is replaced, floats keep their fraction', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        $tree = storedTree('one');
        $tree[0]['call']['temperature'] = 1.0;
        $tree[0]['call']['top_p'] = 0.5;
        $tree[0]['content'] = ['output' => "bad \xB1\x31 byte, ünïcødé / slash"];

        $collector->push($tree);

        $stored = array_values($collector->claim(4_000_000, 60))[0][0];

        expect($stored['call']['temperature'])->toBe(1.0)
            ->and($stored['call']['top_p'])->toBe(0.5)
            ->and($stored['content']['output'])->toBe("bad \u{FFFD}1 byte, ünïcødé / slash")
            ->and($stored['span_id'])->toBe($tree[0]['span_id']);
    });

    it('acking or releasing an unknown, already acked or malformed id does nothing', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        $collector->push(storedTree('one'));
        $ids = array_keys($collector->claim(4_000_000, 60));

        $collector->ack($ids);
        $collector->ack($ids);
        $collector->release($ids, 0);
        $collector->ack(['not-an-id', '1-1', 42, -1]);
        $collector->release(['not-an-id', '1-1', 42, -1], 0);

        $collector->push(storedTree('two'));

        expect($collector->size())->toBe(1)
            ->and(storedNames($collector->claim(4_000_000, 60)))->toBe(['two']);
    });
}

/**
 * Boot Refract with a working Langfuse destination, and record warnings.
 *
 * @param  array<string, mixed>  $config
 */
function bootExport(array $config = []): WarningLog
{
    test()->refreshApplicationWithConfig([
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
        'refract.destinations.otlp.endpoint' => 'https://otlp.test/v1/traces',
        ...$config,
    ]);

    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    // No test here may reach a real destination.
    Http::preventStrayRequests();

    return $log;
}

/**
 * Send one run tree of one neutral run span through the drain.
 *
 * @param  array<string, mixed>  $call
 */
function sendSpan(array $call = []): void
{
    app(Drain::class)->send([[[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => bin2hex(random_bytes(8)), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => ['agent' => 'TimeAgent', ...$call], 'content' => [], 'context' => [], 'events' => [],
    ]]]);
}

/**
 * Fake every request to the destination with the given status, or a failed connection for "network".
 */
function fakeDestination(int|string $status, string $body = ''): void
{
    Http::fake(fn () => $status === 'network' ? Http::failedConnection() : Http::response($body, (int) $status));
}
