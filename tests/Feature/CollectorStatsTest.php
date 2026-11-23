<?php

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\Collectors\DatabaseCollector;
use Anantrp\Refract\Transport\Drain;
use Anantrp\Refract\Transport\DrainTraces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
 * A collector reports what it holds and how long its oldest due tree has
 * waited, and a push warns when no worker has drained the store for 10
 * minutes.
 */

beforeEach(function () {
    Schema::dropIfExists(DatabaseCollector::TABLE);
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
});

it('the database collector reports trees, bytes and the oldest due age', function () {
    $collector = new DatabaseCollector(DB::connection(), 1_000_000);

    expect($collector->stats())->toBe(['trees' => 0, 'bytes' => 0, 'max_bytes' => 1_000_000, 'oldest_age' => null, 'dropped' => null, 'max_attempts' => 0]);

    $collector->push($one = storedTree('one'));
    $this->travel(30)->seconds();
    $collector->push($two = storedTree('two'));
    $this->travel(60)->seconds();

    expect($collector->stats())->toBe([
        'trees' => 2,
        'bytes' => storedBytes($one) + storedBytes($two),
        'max_bytes' => 1_000_000,
        'oldest_age' => 90,
        'dropped' => null,
        'max_attempts' => 0,
    ]);
});

it('a released tree is not due, so it does not count as stalled', function () {
    $collector = new DatabaseCollector(DB::connection());

    $collector->push(storedTree('one'));
    $collector->release(array_keys($collector->claim(1_000_000, 60)), 300);
    $this->travel(200)->seconds();

    expect($collector->stats()['oldest_age'])->toBeNull();

    $this->travel(150)->seconds();

    expect($collector->stats()['oldest_age'])->toBe(50);
});

it('with no worker, the stall warning logs once after 10 minutes', function () {
    config(['refract.drain' => 'queue', 'refract.collector' => 'database']);
    Log::swap($log = new WarningLog);
    Queue::fake();

    app(Drain::class)->send([storedTree('one')]);
    $this->travel(601)->seconds();
    app(Drain::class)->send([storedTree('two')]);
    app(Drain::class)->send([storedTree('three')]);
    $this->travel(61)->seconds();
    app(Drain::class)->send([storedTree('four')]);

    $stalled = array_values(array_filter($log->warnings, fn (string $line) => str_contains($line, 'No worker is draining')));

    expect($stalled)->toBe(['[refract] No worker is draining the refract queue: the oldest stored tree has waited 10 minutes. Run queue:work with --queue=default,refract, or add refract to your workers\' queues.']);
});

it('a store drained in time never warns', function () {
    config(['refract.drain' => 'queue', 'refract.collector' => 'database']);
    $collector = app(Collector::class);
    Log::swap($log = new WarningLog);
    Queue::fake();

    foreach (range(1, 3) as $i) {
        app(Drain::class)->send([storedTree("tree {$i}")]);
        $collector->ack(array_keys($collector->claim(1_000_000, 60)));
        $this->travel(400)->seconds();
    }

    expect($log->warnings)->toBe([]);
});

it('a tree whose worker died holding its lease counts as due once the lease runs out', function () {
    $collector = new DatabaseCollector(DB::connection());

    $collector->push(storedTree('one'));
    $collector->claim(1_000_000, DrainTraces::LEASE);

    $this->travel(DrainTraces::LEASE - 10)->seconds();

    expect($collector->stats()['oldest_age'])->toBeNull();

    $this->travel(90)->seconds();

    expect($collector->stats()['oldest_age'])->toBe(80);
});
