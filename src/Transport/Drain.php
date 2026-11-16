<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Takes the finished run trees from capture and sends them on their way.
 *
 * The sync drain exports the trees in this process, once. It is best
 * effort: a batch the destination cannot take is dropped with one
 * warning. The queue drain puts them in the collector instead, and
 * dispatches a DrainTraces job to export them from the app's workers.
 * Nothing ever reaches the app.
 */
class Drain
{
    /**
     * The seconds an export in this process waits for the destination.
     */
    public const TIMEOUT = 5;

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
        try {
            $result = $this->container->make(Exporter::class)->export($trees);
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return;
        }

        if ($result->isRetryable()) {
            Diagnostics::warn('export.unavailable', 'Spans could not be exported: the destination could not be reached or answered 408, 429 or 5xx. The batch was dropped (an export in this process is not retried).');
        }
    }

    /**
     * Put the given trees in the collector, and make sure a drain job is on its way.
     *
     * The drain job is unique: while one is waiting, dispatching it again
     * is a no-op, so only the first push after the store went idle
     * dispatches one.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    protected function collect(array $trees): void
    {
        try {
            $collector = $this->container->make(Collector::class);

            foreach ($trees as $tree) {
                $collector->push($tree);
            }
        } catch (Throwable $e) {
            Diagnostics::warn('collect.error', 'Run trees could not be stored for the queue drain ('.$e::class.'). They were dropped.');

            return;
        }

        try {
            DrainTraces::dispatch();
        } catch (Throwable $e) {
            Diagnostics::warn('drain.dispatch', 'The queue drain could not be dispatched ('.$e::class.'). The stored trees wait for the next run.');
        }
    }
}
