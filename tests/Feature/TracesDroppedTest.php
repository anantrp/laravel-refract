<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Events\TracesDropped;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Stats;
use Anantrp\Refract\Transport\Collectors\DatabaseCollector;
use Anantrp\Refract\Transport\Drain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/*
 * Every tree Refract loses is counted by reason, fires TracesDropped,
 * and warns, whatever lost it.
 */

beforeEach(function () {
    Schema::dropIfExists(DatabaseCollector::TABLE);
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
});

it('fires with the right count for each reason', function (string $reason, int $expected, Closure $trigger, ?Closure $boot = null) {
    $state = $boot?->call($this);

    // After any app refresh, which would drop the fake.
    Event::fake([TracesDropped::class]);

    $trigger->call($this, $state);

    Event::assertDispatched(TracesDropped::class, fn (TracesDropped $e) => $e->reason === $reason && $e->count === $expected);
    expect(Stats::read()['dropped'])->toHaveKey($reason, $expected);
})->with([
    'full' => ['full', 1, function () {
        $collector = new DatabaseCollector(DB::connection(), storedBytes(storedTree('one')) + 10);
        $collector->push(storedTree('one'));
        $collector->push(storedTree('two'));
    }],
    'decode' => ['decode', 1, function () {
        DB::table(DatabaseCollector::TABLE)->insert(['payload' => 'not json', 'bytes' => 8, 'available_at' => time(), 'reserved_at' => null]);
        (new DatabaseCollector(DB::connection()))->claim(4_000_000, 60);
    }],
    'transaction' => ['transaction', 1, function () {
        DB::transaction(fn () => (new DatabaseCollector(DB::connection()))->push(storedTree('one')));
    }],
    'unavailable' => ['unavailable', 1, function () {
        fakeDestination('network');
        sendSpan();
    }, fn () => bootExport()],
    'paused' => ['paused', 1, function () {
        fakeDestination('network');
        sendSpan();
        sendSpan();
    }, fn () => bootExport()],
    'rejected' => ['rejected', 1, function () {
        fakeDestination(400);
        sendSpan();
    }, fn () => bootExport()],
    'unfinished' => ['unfinished', 2, function ($exporter) {
        $exporter->results = [ExportResult::unfinished(1)];
        app(Drain::class)->send([storedTree('one'), storedTree('two'), storedTree('three')]);
    }, fn () => $this->captureNeutralSpans()],
    'error' => ['error', 3, function ($exporter) {
        $exporter->throws = true;
        app(Drain::class)->send([storedTree('one'), storedTree('two'), storedTree('three')]);
    }, fn () => $this->captureNeutralSpans()],
    'run_cap' => ['run_cap', 1, function () {
        $recorder = app(Recorder::class);
        $recorder->start('run', 'invoke_agent', null, ['agent' => 'TimeAgent']);

        for ($i = 1; $i <= Recorder::MAX_RUN_SPANS; $i++) {
            $recorder->start("tool:{$i}", 'execute_tool', 'run', ['tool' => 'Filler']);
            $recorder->end("tool:{$i}");
        }

        $recorder->end('run');
        $recorder->flush();
    }, fn () => $this->captureNeutralSpans()],
]);

it('counts the children of a span dropped at the run cap too', function () {
    $this->captureNeutralSpans();
    Event::fake([TracesDropped::class]);
    $recorder = app(Recorder::class);
    $recorder->start('run', 'invoke_agent', null, ['agent' => 'TimeAgent']);

    for ($i = 1; $i < Recorder::MAX_RUN_SPANS; $i++) {
        $recorder->start("tool:{$i}", 'execute_tool', 'run', ['tool' => 'Filler']);
        $recorder->end("tool:{$i}");
    }

    $recorder->start('tool:over', 'execute_tool', 'run', ['tool' => 'Dropped']);
    $recorder->start('child', 'chat', 'tool:over', []);
    $recorder->end('child');
    $recorder->end('tool:over');
    $recorder->end('run');
    $recorder->flush();

    Event::assertDispatchedTimes(TracesDropped::class, 1);
    Event::assertDispatched(TracesDropped::class, fn (TracesDropped $e) => $e->reason === 'run_cap' && $e->count === 2);
});

it('a listener that throws or records another drop does not break or recurse', function () {
    bootExport();
    fakeDestination('network');

    Event::listen(TracesDropped::class, fn () => throw new RuntimeException('listener'));
    Event::listen(TracesDropped::class, fn () => Diagnostics::dropped('full', 1, 'collect.full', 'Again.'));

    sendSpan();
    sendSpan();

    expect(Stats::read()['dropped'])->toMatchArray(['unavailable' => 1, 'paused' => 1])
        ->and(Stats::read()['dropped']['full'] ?? 0)->toBeLessThanOrEqual(2);
});

it('a sync export cut short before it sent anything is not a success', function () {
    $exporter = $this->captureNeutralSpans();
    $exporter->results = [ExportResult::unfinished(0)];

    app(Drain::class)->send([storedTree('one')]);

    expect(Stats::read()['last_success'])->toBeNull()
        ->and(Stats::read()['dropped'])->toBe(['unfinished' => 1]);
});
