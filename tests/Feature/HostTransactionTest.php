<?php

use Anantrp\Refract\Capture\FlushPoints;
use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\Collectors\DatabaseCollector;
use Anantrp\Refract\Transport\DrainTraces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Ai\Agents\TimeAgent;

/*
 * A console process hands a run tree on when its top-level run ends, but
 * never inside the app's own database transaction: an export there would
 * hold the transaction open, and a push or a drain job written there would
 * be rolled back with it. The tree waits until no transaction is open.
 */

beforeEach(function () {
    $this->exporter = $this->captureNeutralSpans();
    $this->recorder = app(Recorder::class);

    Diagnostics::reset();
    Log::swap($this->log = new WarningLog);
});

/**
 * Record a top-level run of one span, named so a test can tell runs apart.
 */
function endRun(string $name): void
{
    app(Recorder::class)->start("run:{$name}", 'invoke_agent', null, ['agent' => $name]);
    app(Recorder::class)->end("run:{$name}");
}

/**
 * Get the agent names of the runs handed on so far, in order.
 *
 * @return list<string>
 */
function handedOn(): array
{
    return array_map(fn (array $tree) => $tree[0]['call']['agent'], test()->exporter->trees);
}

it('a run that ends inside a transaction is handed on once it commits, not before', function () {
    DB::transaction(function () {
        endRun('inside');

        expect(handedOn())->toBe([]);
    });

    expect(handedOn())->toBe(['inside']);
});

it('a run that ends inside a transaction that rolls back is still handed on', function () {
    try {
        DB::transaction(function () {
            endRun('inside');

            throw new RuntimeException('The app failed.');
        });
    } catch (RuntimeException) {
        //
    }

    expect(handedOn())->toBe(['inside']);
});

it('a run in a nested transaction waits for the outermost one', function () {
    DB::transaction(function () {
        DB::transaction(fn () => endRun('inner'));

        expect(handedOn())->toBe([]);
    });

    expect(handedOn())->toBe(['inner']);
});

it('a transaction open on any connection this process made holds the run', function () {
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    DB::connection('other')->beginTransaction();

    endRun('inside');

    expect(handedOn())->toBe([]);

    DB::connection('other')->commit();

    expect(handedOn())->toBe(['inside']);
});

it('no database connection is made to find out', function () {
    endRun('alone');

    expect(handedOn())->toBe(['alone'])
        ->and(DB::getConnections())->toBe([]);
});

it('a waiting run leaves with the next run that ends outside a transaction', function () {
    $connection = DB::connection();
    // A transaction ended where Refract cannot see it.
    $connection->unsetEventDispatcher();

    $connection->beginTransaction();
    endRun('inside');
    $connection->commit();

    expect(handedOn())->toBe([]);

    endRun('outside');

    expect(handedOn())->toEqualCanonicalizing(['inside', 'outside'])
        ->and($this->exporter->exports)->toBe(1);
});

it('a flush point inside an open transaction still hands the waiting run on: it is the last chance', function () {
    DB::beginTransaction();

    endRun('inside');
    app(FlushPoints::class)->flush();

    expect(handedOn())->toBe(['inside']);

    DB::rollBack();

    expect($this->exporter->exports)->toBe(1);
});

it('a run that ends outside a transaction is handed on at once', function () {
    DB::transaction(fn () => null);

    endRun('outside');

    expect(handedOn())->toBe(['outside']);
});

it('with the sync drain, no export runs while the app holds a transaction', function () {
    $this->memoryExporter = null;
    $this->refreshApplication();
    Http::fake();

    DB::transaction(function () {
        TimeAgent::fakeTwoSteps();
        TimeAgent::make()->prompt('What time is it?');

        Http::assertNothingSent();
    });

    Http::assertSentCount(1);
});

it('with the database collector on the app connection, a rolled back transaction loses neither the tree nor its drain job', function () {
    $this->refreshApplicationWithConfig(['refract.drain' => 'queue', 'refract.collector' => 'database', 'queue.default' => 'database']);
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations');
    Schema::dropIfExists(DatabaseCollector::TABLE);
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    try {
        DB::transaction(function () {
            TimeAgent::fakeTwoSteps();
            TimeAgent::make()->prompt('What time is it?');

            throw new RuntimeException('The app failed.');
        });
    } catch (RuntimeException) {
        //
    }

    expect(app(Collector::class)->size())->toBe(1)
        ->and(DB::table('jobs')->where('queue', DrainTraces::QUEUE)->count())->toBe(1)
        ->and($log->warnings)->toBe([]);
});
