<?php

use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\WarningLog;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/*
 * A warning is logged at most once per 10 minutes per key, across every
 * process that shares the default cache. The next one says how many were
 * held back meanwhile.
 */

beforeEach(function () {
    Log::swap($this->log = new WarningLog);
});

it('a worker warns again after 10 minutes with the count since the last warning', function () {
    foreach (range(1, 6) as $i) {
        Diagnostics::warn('collect.full', 'The store is full.');
    }

    $this->travel(601)->seconds();
    Diagnostics::warn('collect.full', 'The store is full.');

    expect($this->log->warnings)->toBe([
        '[refract] The store is full.',
        '[refract] The store is full. (5 more since the last warning)',
    ]);
});

it('says nothing more when nothing was held back', function () {
    Diagnostics::warn('collect.full', 'The store is full.');
    $this->travel(601)->seconds();
    Diagnostics::warn('collect.full', 'The store is full.');

    expect($this->log->warnings)->toBe(['[refract] The store is full.', '[refract] The store is full.']);
});

it('100 fresh processes in one window log once', function () {
    foreach (range(1, 100) as $process) {
        Diagnostics::reset();
        Diagnostics::warn('collect.full', 'The store is full.');
    }

    expect($this->log->warnings)->toHaveCount(1);
});

it('with the cache down, each process still warns once per window', function () {
    $cache = Mockery::mock(Cache::class);
    $cache->shouldReceive(Mockery::any())->andThrow(new RuntimeException('down'));
    app()->instance(Cache::class, $cache);

    foreach (range(1, 3) as $i) {
        Diagnostics::warn('collect.full', 'The store is full.');
    }

    $this->travel(601)->seconds();
    Diagnostics::warn('collect.full', 'The store is full.');

    Diagnostics::reset();
    Diagnostics::warn('collect.full', 'The store is full.');

    expect($this->log->warnings)->toBe([
        '[refract] The store is full.',
        '[refract] The store is full. (2 more since the last warning)',
        '[refract] The store is full.',
    ]);
});

it('more than 10 keys all log', function () {
    foreach (range(1, 12) as $key) {
        Diagnostics::warn("key.{$key}", "Warning {$key}.");
    }

    expect($this->log->warnings)->toHaveCount(12);
});
