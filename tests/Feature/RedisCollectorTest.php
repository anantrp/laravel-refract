<?php

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\Collectors\RedisCollector;
use Anantrp\Refract\Transport\Drain;
use Anantrp\Refract\Transport\DrainTraces;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

pest()->group('redis');

/*
 * The Redis collector against a real Redis (REDIS_HOST, 127.0.0.1 by
 * default). Each test uses keys of its own and deletes them. Skipped when
 * Redis cannot be reached; leave the group out with --exclude-group=redis.
 */

/**
 * Get the Redis connection the tests use, or skip the test when Redis cannot be reached.
 */
function redisConnection(): Connection
{
    try {
        $redis = app('redis')->connection();
        $redis->ping();
    } catch (Throwable $e) {
        test()->markTestSkipped('Redis is not reachable ('.$e::class.').');
    }

    return $redis;
}

/**
 * Make a collector on keys of this test's own, deleted when the test ends.
 */
function redisCollector(int $maxBytes = RedisCollector::MAX_BYTES): RedisCollector
{
    $redis = redisConnection();
    $key = 'refract-test-'.bin2hex(random_bytes(6)).':{traces}';

    test()->redisKeys = [$key, "{$key}:bytes", "{$key}:dropped", "{$key}:delayed", "{$key}:delayed:trees"];

    return new RedisCollector($redis, $key, $maxBytes);
}

beforeEach(function () {
    Diagnostics::reset();
    Log::swap($this->log = new WarningLog);
});

afterEach(function () {
    if (isset($this->redisKeys)) {
        redisConnection()->del(...$this->redisKeys);
    }
});

itBehavesLikeAStoredCollector(fn (int $maxBytes) => redisCollector($maxBytes));

it('an entry that is not a tree is dropped with one warning when claimed, and the others are claimed', function () {
    $collector = redisCollector();

    $collector->push(storedTree('one'));
    redisConnection()->eval("return redis.call('xadd', KEYS[1], '*', 'tree', 'not json')", 1, $this->redisKeys[0]);
    $collector->push(storedTree('two'));

    expect(storedNames($collector->claim(4_000_000, 60)))->toBe(['one', 'two'])
        ->and($collector->size())->toBe(2)
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('could not be decoded');
});

it('the byte count starts over when the store is empty, so it cannot drift for good', function () {
    $tree = storedTree('one', str_repeat('x', 1_000));
    $collector = redisCollector(maxBytes: strlen((string) json_encode($tree)) + 10);

    // A count left too high, as by a manual delete of the stream.
    redisConnection()->set($this->redisKeys[1], 1_000_000);

    $collector->push($tree);

    expect($collector->size())->toBe(1)
        ->and($collector->dropped())->toBe(0);
});

it('end to end: the queue drain stores trees in Redis and a drain job exports and acks them', function () {
    $collector = redisCollector();
    $exporter = $this->captureNeutralSpans();
    app()->instance(Collector::class, $collector);
    config(['refract.drain' => 'queue']);
    Queue::fake();

    app(Drain::class)->send([storedTree('one'), storedTree('two')]);

    Queue::assertPushed(DrainTraces::class, 1);

    (new DrainTraces)->handle(app());

    expect(array_map(fn (array $tree) => $tree[0]['call']['agent'], $exporter->trees))->toBe(['one', 'two'])
        ->and($collector->size())->toBe(0);
});

it('the redis collector uses the configured connection', function () {
    redisConnection();
    $this->refreshApplicationWithConfig(['refract.collectors.redis.connection' => 'cache']);

    $collector = app(Collector::class);
    $redis = (fn () => $this->redis)->call($collector);

    expect($collector)->toBeInstanceOf(RedisCollector::class)
        ->and($redis->getName())->toBe('cache');
});

it('an invalid collector is the redis collector, with one warning', function () {
    redisConnection();
    $this->refreshApplicationWithConfig(['refract.collector' => 'file']);
    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    expect(app(Collector::class))->toBeInstanceOf(RedisCollector::class)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('refract.collector');
});
