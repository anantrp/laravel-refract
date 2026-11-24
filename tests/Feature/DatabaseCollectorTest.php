<?php

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Stats;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\Collectors\DatabaseCollector;
use Anantrp\Refract\Transport\Drain;
use Anantrp\Refract\Transport\DrainTraces;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/*
 * The database collector on the tests' SQLite database, with the
 * published migration. tests/Feature/MySqlCollectorTest.php runs the
 * SKIP LOCKED claim against a real MySQL.
 */

beforeEach(function () {
    // A test that moves the collector to another connection rolls the migration back there, not here.
    Schema::dropIfExists(DatabaseCollector::TABLE);

    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

    Diagnostics::reset();
    Log::swap($this->log = new WarningLog);
});

/**
 * Make a database collector on the tests' default connection.
 */
function databaseCollector(int $maxBytes = DatabaseCollector::MAX_BYTES): DatabaseCollector
{
    return new DatabaseCollector(DB::connection(), $maxBytes);
}

itBehavesLikeAStoredCollector(fn (int $maxBytes) => databaseCollector($maxBytes));

it('the migration creates the refract_traces table', function () {
    expect(Schema::getColumnListing(DatabaseCollector::TABLE))
        ->toEqualCanonicalizing(['id', 'payload', 'bytes', 'available_at', 'reserved_at', 'attempts']);
});

it('a row that is not a tree is dropped with one warning when claimed, and the others are claimed', function () {
    $collector = databaseCollector();

    $collector->push(storedTree('one'));
    DB::table(DatabaseCollector::TABLE)->insert(['payload' => 'not json', 'bytes' => 8, 'available_at' => time(), 'reserved_at' => null]);
    $collector->push(storedTree('two'));

    expect(storedNames($collector->claim(4_000_000, 60)))->toBe(['one', 'two'])
        ->and($collector->size())->toBe(2)
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('could not be decoded');
});

it('leases and delays follow the app clock', function () {
    $collector = databaseCollector();

    $collector->push(storedTree('one'));
    $ids = array_keys($collector->claim(4_000_000, 60));

    $this->travel(59)->seconds();
    expect($collector->claim(4_000_000, 60))->toBe([]);

    $this->travel(1)->seconds();
    expect(array_keys($collector->claim(4_000_000, 60)))->toBe($ids);

    $collector->release($ids, 300);

    $this->travel(299)->seconds();
    expect($collector->claim(4_000_000, 60))->toBe([]);

    $this->travel(1)->seconds();
    expect(storedNames($collector->claim(4_000_000, 60)))->toBe(['one']);
});

it('a tree dropped at the cap is counted for every process, by reason', function () {
    $one = storedTree('one');
    $collector = databaseCollector(maxBytes: intdiv(3 * storedBytes($one), 2));

    $collector->push($one);
    $collector->push(storedTree('two'));

    expect(Stats::read()['dropped'])->toBe(['full' => 1]);
});

it('a push inside a transaction on its connection is skipped with one warning, and the transaction is untouched', function () {
    $collector = databaseCollector();

    DB::table(DatabaseCollector::TABLE)->insert(['payload' => '[]', 'bytes' => 2, 'available_at' => 0, 'reserved_at' => null]);

    DB::transaction(function () use ($collector) {
        DB::table(DatabaseCollector::TABLE)->delete();

        $collector->push(storedTree('one'));
    });

    expect($collector->size())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0)
        ->and($this->log->warnings)->toHaveCount(1)
        ->and($this->log->warnings[0])->toContain('open database transaction');

    $collector->push(storedTree('two'));

    expect(storedNames($collector->claim(4_000_000, 60)))->toBe(['two']);
});

it('a released tree goes behind the trees that were due before it, so a batch that keeps failing never blocks the store', function () {
    $collector = databaseCollector();

    $collector->push(storedTree('failing'));
    $collector->push(storedTree('next'));
    $this->travel(10)->seconds();

    $failing = $collector->claim(1, 60);
    expect(storedNames($failing))->toBe(['failing']);

    $collector->release(array_keys($failing), 0);
    $collector->push(storedTree('newer'));

    expect(storedNames($collector->claim(4_000_000, 60)))->toBe(['next', 'failing', 'newer']);
});

it('the stored bytes are summed from an index, never from the payloads', function () {
    $plan = collect(DB::select('EXPLAIN QUERY PLAN SELECT SUM(bytes) FROM '.DatabaseCollector::TABLE))->pluck('detail')->implode(' ');

    expect($plan)->toContain('COVERING INDEX');
});

it('the migration indexes due order and the bytes', function () {
    $indexes = collect(Schema::getIndexes(DatabaseCollector::TABLE))->pluck('columns')->all();

    expect($indexes)->toContain(['available_at', 'id'])
        ->toContain(['bytes']);
});

it('end to end: the queue drain stores trees in the database and a drain job exports and acks them', function () {
    $exporter = $this->captureNeutralSpans();
    config(['refract.drain' => 'queue', 'refract.collector' => 'database']);
    Queue::fake();

    app(Drain::class)->send([storedTree('one'), storedTree('two')]);

    Queue::assertPushed(DrainTraces::class, 1);

    (new DrainTraces)->handle(app());

    expect(array_map(fn (array $tree) => $tree[0]['call']['agent'], $exporter->trees))->toBe(['one', 'two'])
        ->and(app(Collector::class)->size())->toBe(0);
});

it('the database collector uses the configured connection', function () {
    $this->refreshApplicationWithConfig([
        'refract.collector' => 'database',
        'refract.collectors.database.connection' => 'traces',
        'database.connections.traces' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);

    $collector = app(Collector::class);
    $database = (fn () => $this->database)->call($collector);

    expect($collector)->toBeInstanceOf(DatabaseCollector::class)
        ->and($database->getName())->toBe('traces');
});

it('the migration runs on the collector connection, or the default one when it is null', function () {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_16_000001_create_refract_traces_table.php';

    expect($migration->getConnection())->toBeNull();

    config([
        'refract.collectors.database.connection' => 'traces',
        'database.connections.traces' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);

    expect($migration->getConnection())->toBe('traces');
});

it('the migration is published under the refract-migrations tag', function () {
    $paths = ServiceProvider::pathsToPublish(RefractServiceProvider::class, 'refract-migrations');

    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(dirname(__DIR__, 2).'/database/migrations')])
        ->and(array_values($paths))->toBe([database_path('migrations')]);
});

it('a push does not sum the table while the running total is fresh', function () {
    $collector = databaseCollector();
    $sums = 0;

    DB::listen(function ($query) use (&$sums) {
        $sums += str_contains(strtolower($query->sql), 'sum(') ? 1 : 0;
    });

    foreach (range(1, 10) as $i) {
        $collector->push(storedTree("tree {$i}"));
    }

    expect($sums)->toBe(1)
        ->and($collector->size())->toBe(10);
});

it('the running total is summed again after 60 seconds', function () {
    $collector = databaseCollector();
    $sums = 0;

    DB::listen(function ($query) use (&$sums) {
        $sums += str_contains(strtolower($query->sql), 'sum(') ? 1 : 0;
    });

    $collector->push(storedTree('one'));
    $this->travel(61)->seconds();
    $collector->push(storedTree('two'));

    expect($sums)->toBe(2);
});

it('the total is reconciled on claim, so a row deleted behind the collector\'s back frees its bytes', function () {
    $one = storedTree('one', str_repeat('x', 1_000));
    $collector = databaseCollector(maxBytes: intdiv(5 * storedBytes($one), 2));

    $collector->push($one);
    $collector->push(storedTree('two', str_repeat('x', 1_000)));
    DB::table(DatabaseCollector::TABLE)->orderBy('id')->limit(1)->delete();

    // The total still counts the deleted row.
    expect($collector->push(storedTree('three', str_repeat('x', 1_000))))->toBeFalse();

    $collector->release(array_keys($collector->claim(4_000_000, 60)), 0);

    expect($collector->push(storedTree('four', str_repeat('x', 1_000))))->toBeTrue();
});

it('with the cache down, the cap still holds, summing each push', function () {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive(Mockery::any())->andThrow(new RuntimeException('down'));
    app()->instance(Repository::class, $cache);

    $one = storedTree('one', str_repeat('x', 1_000));
    $collector = databaseCollector(maxBytes: intdiv(3 * storedBytes($one), 2));

    expect($collector->push($one))->toBeTrue()
        ->and($collector->push(storedTree('two', str_repeat('x', 1_000))))->toBeFalse();
});
