<?php

use Anantrp\Refract\Contracts\Collector;

/*
 * Helpers shared by the test files: the run trees the collector tests
 * store, and the behaviour every stored collector shares.
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
    return strlen((string) json_encode($tree));
}

/**
 * Define the tests every stored collector passes, in the calling test file.
 *
 * The given closure makes an empty collector, capped at the given bytes,
 * whose dropped() counts the trees it dropped at the cap.
 *
 * @param  Closure(int): Collector  $make
 */
function itBehavesLikeAStoredCollector(Closure $make): void
{
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

    it('a claim adds trees until it reaches the bytes, and the rest wait for the next claim', function () use ($make) {
        $collector = $make(64 * 1024 * 1024);

        foreach (['one', 'two', 'three'] as $name) {
            $collector->push(storedTree($name, str_repeat('x', 1_000)));
        }

        // Each tree is over 1,000 bytes: the second takes the claim past 1,500.
        expect(storedNames($collector->claim(1_500, 60)))->toBe(['one', 'two'])
            ->and(storedNames($collector->claim(1_500, 60)))->toBe(['three']);
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
        $collector = $make(2 * storedBytes(storedTree('one', str_repeat('x', 1_000))) + 10);

        $collector->push(storedTree('one', str_repeat('x', 1_000)));
        $collector->push(storedTree('two', str_repeat('x', 1_000)));
        $collector->push(storedTree('three', str_repeat('x', 1_000)));
        $collector->push(storedTree('four', str_repeat('x', 1_000)));

        expect($collector->size())->toBe(2)
            ->and($collector->dropped())->toBe(2)
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
        $collector = $make(storedBytes(storedTree('one', str_repeat('x', 1_000))) + 10);

        $collector->push(storedTree('one', str_repeat('x', 1_000)));
        $collector->release(array_keys($collector->claim(4_000_000, 60)), 300);
        $collector->push(storedTree('two', str_repeat('x', 1_000)));

        expect($collector->size())->toBe(1)
            ->and($collector->dropped())->toBe(1);
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
