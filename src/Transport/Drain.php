<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ReportsStats;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Stats;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Takes the finished run trees from capture and sends them on their way.
 *
 * The sync drain exports the trees in this process, once, within
 * TIMEOUT seconds in all. It is best effort: a batch the destination
 * cannot take, or cannot take in time, is dropped with one warning.
 * After a failed export, ExportBreaker pauses exports for a while.
 * The queue drain puts them in the collector instead, and dispatches
 * a DrainTraces job to export them from the app's workers.
 * Nothing ever reaches the app.
 */
class Drain
{
    /**
     * The seconds an export in this process takes at most, all its requests together.
     */
    public const TIMEOUT = 5;

    /**
     * The seconds an export in this process waits to connect to the destination, so a host that never answers costs 1 second, not 5.
     */
    public const CONNECT_TIMEOUT = 1;

    /**
     * The seconds the oldest due tree in the store may wait before a push warns that no worker drains it.
     */
    public const STALL_AFTER = 600;

    /**
     * The seconds between two looks at the store for a stall, across processes.
     */
    protected const STALL_EVERY = 60;

    /**
     * The cache key that spaces out the looks at the store for a stall.
     */
    protected const STALL_CHECKED = 'refract:stall:checked';

    /**
     * Create a new drain instance, "sync" or "queue". The exporter or collector is made on the first send.
     */
    public function __construct(protected Container $container, protected string $drain = 'sync') {}

    /**
     * Send the given finished run trees, each a list of neutral spans.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    public function send(array $trees): void
    {
        if ($trees === []) {
            return;
        }

        if ($this->drain === 'queue') {
            $this->collect($trees);
        } else {
            $this->export($trees);
        }
    }

    /**
     * Export the given trees in this process, once.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    protected function export(array $trees): void
    {
        $breaker = new ExportBreaker($this->container);

        if ($breaker->isOpen()) {
            Diagnostics::dropped('paused', count($trees), 'export.paused', 'Spans were dropped without trying the destination: it failed recently, so exports are paused for a while.');

            return;
        }

        try {
            $result = $this->container->make(Exporter::class)->export($trees, self::TIMEOUT);
        } catch (Throwable $e) {
            Stats::failed();
            Diagnostics::dropped('error', count($trees), 'export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return;
        }

        if ($result->isRetryable()) {
            $pause = $breaker->failed();
            Stats::failed();

            Diagnostics::dropped('unavailable', count($trees) - $result->done, 'export.unavailable', "Spans could not be exported: the destination could not be reached or answered 408, 429 or 5xx. The batch was dropped (an export in this process is not retried), and exports pause for {$pause} seconds.");

            return;
        }

        $breaker->answered();

        if ($result->isRejected()) {
            Stats::failed();
            // The exporter warned with the destination's answer.
            Diagnostics::dropped('rejected', count($trees));

            return;
        }

        Stats::exported();

        if ($result->isUnfinished()) {
            $dropped = count($trees) - $result->done;

            Diagnostics::dropped('unfinished', $dropped, 'export.unfinished', 'An export in this process took its whole '.self::TIMEOUT." seconds: {$dropped} of ".count($trees).' run trees were not sent and were dropped.');
        }
    }

    /**
     * Warn when the oldest due tree in the store has waited past STALL_AFTER: no worker is draining it.
     *
     * The store is looked at most once a minute across processes, so a
     * push costs no more than it did. A store that cannot report is not
     * looked at.
     */
    protected function checkStall(Collector $collector): void
    {
        if (! $collector instanceof ReportsStats) {
            return;
        }

        try {
            if (! $this->container->make(Cache::class)->add(self::STALL_CHECKED, true, self::STALL_EVERY)) {
                return;
            }

            $age = $collector->stats()['oldest_age'];
        } catch (Throwable) {
            return;
        }

        if ($age !== null && $age > self::STALL_AFTER) {
            $minutes = intdiv($age, 60);

            Diagnostics::warn('drain.stalled', "No worker is draining the refract queue: the oldest stored tree has waited {$minutes} minutes. Run queue:work with --queue=default,refract, or add refract to your workers' queues.");
        }
    }

    /**
     * Put the given trees in the collector, and make sure a drain job is on its way.
     *
     * Each tree is pushed on its own, so a push that fails drops only its
     * tree. The drain job is dispatched when at least one tree was stored.
     * It is unique: while one is waiting, dispatching it again is a no-op,
     * so only the first push after the store went idle dispatches one.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    protected function collect(array $trees): void
    {
        try {
            $collector = $this->container->make(Collector::class);
        } catch (Throwable $e) {
            Diagnostics::dropped('error', count($trees), 'collect.error', 'Run trees could not be stored for the queue drain ('.$e::class.'). They were dropped.');

            return;
        }

        $stored = 0;

        foreach ($trees as $tree) {
            try {
                $stored += $collector->push($tree) ? 1 : 0;
            } catch (Throwable $e) {
                Diagnostics::dropped('error', 1, 'collect.error', 'Run trees could not be stored for the queue drain ('.$e::class.'). They were dropped.');
            }
        }

        if ($stored === 0) {
            return;
        }

        $this->checkStall($collector);

        try {
            DrainTraces::dispatch();
        } catch (Throwable $e) {
            Diagnostics::warn('drain.dispatch', 'The queue drain could not be dispatched ('.$e::class.'). The stored trees wait for the next run.');
        }
    }
}
