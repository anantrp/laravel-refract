<?php

use Anantrp\Refract\Support\Stats;
use Illuminate\Contracts\Cache\Repository as Cache;

/*
 * Refract keeps its drop counts and its last export times in the app's
 * default cache, so every process adds to the same counts and a command
 * run later can read them.
 */

beforeEach(function () {
    // A store that outlives one process, like file or Redis. The array store would be per process.
    config(['cache.default' => 'file', 'cache.stores.file.path' => sys_get_temp_dir().'/refract-stats-'.getmypid()]);
    app(Cache::class)->getStore()->flush();
    Stats::reset();
});

it('counts drops by reason across fresh processes', function () {
    Stats::dropped('full', 3);
    Stats::reset();
    Stats::dropped('full', 2);
    Stats::dropped('paused', 1);

    expect(Stats::read()['dropped'])->toBe(['full' => 5, 'paused' => 1])
        ->and(Stats::read()['durable'])->toBeTrue();
});

it('records the last success and failure times', function () {
    $this->travelTo('2026-11-18 10:00:00');
    Stats::exported();

    $this->travelTo('2026-11-18 10:05:00');
    Stats::failed();

    expect(Stats::read())->toMatchArray([
        'last_success' => strtotime('2026-11-18 10:00:00'),
        'last_failure' => strtotime('2026-11-18 10:05:00'),
    ]);
});

it('reads nothing before anything happened', function () {
    expect(Stats::read())->toBe(['dropped' => [], 'last_success' => null, 'last_failure' => null, 'durable' => true]);
});

it('falls back to the process when the cache throws', function () {
    $cache = Mockery::mock(Cache::class);
    $cache->shouldReceive(Mockery::any())->andThrow(new RuntimeException('down'));
    app()->instance(Cache::class, $cache);

    Stats::dropped('full', 2);
    Stats::exported();

    expect(Stats::read())->toMatchArray(['dropped' => ['full' => 2], 'durable' => false])
        ->and(Stats::read()['last_success'])->toBeInt();
});

it('is not durable on the array store', function () {
    config(['cache.default' => 'array']);

    Stats::dropped('full', 1);

    expect(Stats::read())->toMatchArray(['dropped' => ['full' => 1], 'durable' => false]);
});
