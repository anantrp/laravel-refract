<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Stats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\InteractsWithTime;
use Throwable;

/**
 * Exports the collected run trees from the app's own queue workers.
 *
 * The job claims trees, exports them and acks them, until the store is
 * empty or its time budget is spent; then it dispatches the next drain.
 * When the destination cannot take a batch now, the batch is released
 * with a backoff and the drain stops until then. It never throws, so it
 * never reaches the failed jobs table.
 *
 * It keeps to a time limit, so it ends well before its worker timeout and
 * before any queue connection's retry_after, and a claim's lease outlives
 * it: a drain is never killed or run twice in the middle of an export. A
 * drain that is killed anyway (a crash, a deploy) is never failed: the
 * queue gives it to a worker again after its retry_after, and the trees it
 * claimed are claimed again once their lease runs out.
 *
 * It is unique until it starts processing: while one drain is waiting,
 * dispatching another is a no-op, and a running drain can dispatch the
 * next one. The lock lives in the app's default cache store, so the
 * store must be shared by every process for the lock to be shared too.
 */
class DrainTraces implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, InteractsWithTime, Queueable;

    /**
     * The queue the drain runs on, of the app's default queue connection.
     */
    public const QUEUE = 'refract';

    /**
     * The seconds a drain waits after the push that dispatches it, so a burst of runs leaves together.
     */
    public const DELAY = 5;

    /**
     * The seconds an export from the drain waits for the destination.
     */
    public const TIMEOUT = 15;

    /**
     * The seconds an export from the drain waits to connect to the destination.
     */
    public const CONNECT_TIMEOUT = 5;

    /**
     * The most bytes of tree JSON one claim takes, however small the trees are stored: about one 4 MB request.
     */
    public const CLAIM_BYTES = 4_000_000;

    /**
     * The seconds a claim holds its trees: twice the job's timeout, so a drain still running never loses its trees to another.
     */
    public const LEASE = 120;

    /**
     * The seconds one drain starts new claims for, before it hands over to the next drain.
     */
    protected const int BUDGET = 20;

    /**
     * The seconds after the drain starts by which its exports end.
     *
     * A claim is at most CLAIM_BYTES and one tree, and a tree over a
     * request's limit is sent in more than one request: the export is
     * given what is left of this limit, never starts a request it could
     * not finish in time, and leaves the rest of the claim for the next
     * drain. With the claims, acks and encoding around it, the drain ends
     * well within its 60 second timeout, itself within the 90 second
     * retry_after of Laravel's default queue connections.
     */
    protected const int LIMIT = 45;

    /**
     * The seconds to wait after each failed export in a row. A Retry-After is waited for when it is longer.
     *
     * @var list<int>
     */
    protected const BACKOFF = [10, 60, 300];

    /**
     * The most seconds the drain waits after a failed export, a Retry-After included.
     */
    protected const MAX_BACKOFF = 300;

    /**
     * The seconds to wait before looking again when trees are stored but none can be claimed yet.
     */
    protected const IDLE = 30;

    /**
     * The seconds the unique lock outlives the drain's delay, so a lost drain job never blocks the next one for good.
     */
    protected const LOCK_MARGIN = 60;

    /**
     * The failures alone, each while the other half of its split went through, after which a tree is dropped as poison.
     */
    public const DROP_AFTER = 10;

    /**
     * The failed exports in a row, nothing delivered between them, after which the destination is taken to be down.
     *
     * Enough for the search to reach a half that goes through even when
     * two bad trees are claimed together: a real outage costs that many
     * requests before the drain backs off.
     */
    protected const OUTAGE_AFTER = 5;

    /**
     * The seconds a tree set aside waits while the destination takes others: one blamed for a failure, one with no evidence either way, or one whose unsent spans could not be stored.
     */
    protected const ASIDE_DELAY = 60;

    /**
     * What a send of trees came to: the destination took every one (or refused it for good), it took some, it failed on them taking none, or the drain stopped.
     */
    protected const SENT = 'sent';

    protected const PARTIAL = 'partial';

    protected const FAILED = 'failed';

    protected const STOPPED = 'stopped';

    /**
     * The cache key of the backoff shared by every drain: when the next export may be tried, and the failures in a row.
     */
    protected const BACKOFF_KEY = 'refract:drain:backoff';

    /**
     * The drain is never failed for its attempts: a drain killed by a crash or its timeout is given to a worker again.
     *
     * The drain never throws, so it is attempted again only when its
     * worker died. A retry after a failed export is a new drain,
     * dispatched by this one.
     */
    public int $tries = 0;

    /**
     * The seconds the worker lets the drain run: longer than LIMIT, shorter than LEASE.
     */
    public int $timeout = 60;

    /**
     * Whether the destination took anything in this drain.
     */
    protected bool $delivered = false;

    /**
     * Whether the destination took anything of the current claim.
     */
    protected bool $claimDelivered = false;

    /**
     * The exports that failed in a row, nothing delivered between them.
     */
    protected int $failuresInRow = 0;

    /**
     * The trees of the current claim that failed alone with no evidence of what failed: the tree, or the destination.
     *
     * @var list<array-key>
     */
    protected array $unproven = [];

    /**
     * The trees of the current claim, by id.
     *
     * @var array<array-key, list<array<string, mixed>>>
     */
    protected array $trees = [];

    /**
     * The ids of the current claim's trees that are neither acked nor given back yet, in claim order.
     *
     * @var array<array-key, true>
     */
    protected array $open = [];

    /**
     * Create a new drain job instance that runs after the given seconds.
     *
     * It never waits for the app's database transactions: a queue
     * connection with after_commit would hold the drain until they
     * commit, and drop it on a rollback.
     */
    public function __construct(public int $wait = self::DELAY)
    {
        $this->onQueue(self::QUEUE)->delay($wait)->beforeCommit();
    }

    /**
     * Get the seconds the unique lock is held: until the drain is due, and a margin.
     */
    public function uniqueFor(): int
    {
        return $this->wait + self::LOCK_MARGIN;
    }

    /**
     * Export the stored trees, and never throw.
     */
    public function handle(Container $container): void
    {
        try {
            $collector = $container->make(Collector::class);
            $cache = $container->make(Cache::class);
        } catch (Throwable $e) {
            $this->broke($e);

            return;
        }

        try {
            $exporter = $container->make(Exporter::class);
        } catch (Throwable $e) {
            $this->withoutExporter($collector, $cache, $e);

            return;
        }

        try {
            $this->drain($collector, $exporter, $cache);
        } catch (Throwable $e) {
            $this->broke($e);
        }
    }

    /**
     * Warn that the drain failed, and dispatch the next one for when the trees it may have claimed are due again.
     */
    protected function broke(Throwable $e): void
    {
        Diagnostics::warn('drain.error', 'The queue drain failed ('.$e::class.'). The trees it claimed are sent again when their lease runs out.');

        try {
            $this->next(self::LEASE);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Warn that no exporter could be made, and try again with the backoff of a failed export while trees are stored.
     */
    protected function withoutExporter(Collector $collector, Cache $cache, Throwable $e): void
    {
        Diagnostics::warn('drain.exporter', 'The queue drain could not make the exporter ('.$e::class.'). Check the destination settings; the stored trees are kept.');

        try {
            if ($collector->size() > 0) {
                $this->next($this->failureDelay($cache, ExportResult::retryable()));
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * Claim, export and ack until the store is empty, the destination cannot take a batch, or the time is spent.
     */
    protected function drain(Collector $collector, Exporter $exporter, Cache $cache): void
    {
        $started = hrtime(true);
        [$until, $failures] = $this->backoffState($cache);
        $this->delivered = false;
        $this->failuresInRow = 0;

        // Another drain was told to wait: this one never sends before then.
        if ($until > $now = $this->currentTime()) {
            $this->next($until - $now);

            return;
        }

        while ($this->elapsed($started) < static::BUDGET) {
            $trees = $collector->claim(self::CLAIM_BYTES, self::LEASE);

            if ($trees === []) {
                // The stored trees are released for later, or claimed by another drain whose lease may run out.
                if ($collector->size() > 0) {
                    $this->next(self::IDLE);
                }

                return;
            }

            if (! $this->exportClaim($collector, $exporter, $cache, $trees, $started)) {
                return;
            }

            if ($failures > 0) {
                $cache->forget(self::BACKOFF_KEY);
                $failures = 0;
            }
        }

        if ($collector->size() > 0) {
            $this->next(0);
        }
    }

    /**
     * Export the claimed trees, finding the trees the destination fails on by halving, and get whether the drain goes on.
     *
     * The claim is sent as one batch. When it fails without delivering
     * anything, it is split in two halves and both are sent, and each half
     * that failed is split again, until the trees it fails on are alone. A
     * tree is blamed with a failed attempt only when it failed alone below
     * a split whose other half went through in full, so the destination
     * was up, and it is dropped as poison at DROP_AFTER. Trees that failed
     * before are spread across the claim, so the next search tells two bad
     * trees, or a bad tree and a healthy one blamed beside it, apart. A tree that failed
     * with no such evidence counts nothing: it waits with the backoff when
     * nothing of the claim went through, else for ASIDE_DELAY.
     *
     * OUTAGE_AFTER failed exports in a row mean the destination is down:
     * every open tree is given back, nothing is counted, and the drain
     * backs off. So an outage or a flaky destination never drops a healthy
     * tree. A busy destination (429, Retry-After) stops the drain at once.
     *
     * Every claimed tree ends acked, blamed, or given back: when the drain
     * stops, whatever is still open goes back to the store.
     *
     * @param  array<array-key, list<array<string, mixed>>>  $trees
     */
    protected function exportClaim(Collector $collector, Exporter $exporter, Cache $cache, array $trees, int|float $started): bool
    {
        $this->begin($trees);
        $ids = $this->spread($collector, array_keys($trees));

        $sent = $this->send($collector, $exporter, $cache, $ids, $started, searching: false);

        if ($sent === self::FAILED) {
            $sent = $this->isolate($collector, $exporter, $cache, $ids, $started, evidence: false);
        }

        if ($sent === self::STOPPED) {
            return false;
        }

        if ($this->unproven !== [] && ! $this->claimDelivered) {
            // Nothing of the claim went through: the destination may be down.
            $this->retryLater($collector, $cache, ExportResult::retryable());

            return false;
        }

        $this->release($collector, $this->unproven, self::ASIDE_DELAY);

        // Never left claimed: a tree still open goes back at once.
        $this->release($collector, $this->openIds(), 0);

        return true;
    }

    /**
     * Order the given trees so the ones that failed before are spread evenly among the others.
     *
     * Bad trees given back together come back together: side by side, the
     * halving would leave them alone together, with no half that goes
     * through to tell them from a failing destination. Spread apart, the
     * first splits put them in different halves.
     *
     * @param  list<array-key>  $ids
     * @return list<array-key>
     */
    protected function spread(Collector $collector, array $ids): array
    {
        try {
            $attempts = $collector->attempts($ids);
        } catch (Throwable) {
            return $ids;
        }

        $failed = array_values(array_filter($ids, fn (int|string $id) => ($attempts[$id] ?? 0) > 0));

        if ($failed === [] || count($failed) === count($ids)) {
            return $ids;
        }

        $others = array_values(array_filter($ids, fn (int|string $id) => ($attempts[$id] ?? 0) === 0));
        $count = count($ids);
        $at = [];

        foreach ($failed as $index => $id) {
            // The middle of each of count($failed) equal stretches of the claim.
            $at[intdiv((2 * $index + 1) * $count, 2 * count($failed))] = $id;
        }

        $ordered = [];

        for ($position = 0; $position < $count; $position++) {
            $next = $at[$position] ?? array_shift($others);

            if ($next !== null) {
                $ordered[] = $next;
            }
        }

        return $ordered;
    }

    /**
     * Start on the given claim: every tree open, none set aside, nothing delivered.
     *
     * @param  array<array-key, list<array<string, mixed>>>  $trees
     */
    protected function begin(array $trees): void
    {
        $this->trees = $trees;
        $this->open = array_fill_keys(array_keys($trees), true);
        $this->unproven = [];
        $this->claimDelivered = false;
    }

    /**
     * Export the given trees, and get whether the destination took them all, took some, failed on them taking none, or the drain stopped.
     *
     * What an export delivered is settled first. When it delivered some of
     * the trees, the rest are sent again: the first of them may never have
     * been sent, so nothing is blamed for that failure. When they fail
     * again, the requests that went through just before are the evidence
     * that the destination is up.
     *
     * @param  list<array-key>  $ids
     */
    protected function send(Collector $collector, Exporter $exporter, Cache $cache, array $ids, int|float $started, bool $searching): string
    {
        $result = $this->export($exporter, $this->only($this->trees, $ids), static::LIMIT - $this->elapsed($started));

        if ($result->isUnfinished()) {
            $this->settle($collector, $ids, $result);

            if ($searching) {
                // A failing tree was being looked for: the destination is failing, so the next drain waits.
                $this->retryLater($collector, $cache, ExportResult::retryable());
            } else {
                $this->release($collector, $this->openIds(), 0);
                $this->next(0);
            }

            return self::STOPPED;
        }

        if (! $result->isRetryable()) {
            $this->ack($collector, $ids);

            // Trees refused for good say nothing of whether the destination takes others.
            return $result->isOk() && $result->refused < count($ids) ? self::SENT : self::PARTIAL;
        }

        $progressed = $result->done > 0 || $result->rest !== [];
        $left = $this->settle($collector, $ids, $result);

        if ($result->isBusy()) {
            $this->retryLater($collector, $cache, $result);

            return self::STOPPED;
        }

        if (! $progressed) {
            if (++$this->failuresInRow >= self::OUTAGE_AFTER) {
                $this->retryLater($collector, $cache, $result);

                return self::STOPPED;
            }

            return self::FAILED;
        }

        if ($left === []) {
            return self::PARTIAL;
        }

        $sent = $this->send($collector, $exporter, $cache, $left, $started, $searching);

        // When the requests before them in the same export were taken, the destination was up. A refusal is no sign of that.
        if ($sent === self::FAILED && count($left) > 1) {
            $sent = $this->isolate($collector, $exporter, $cache, $left, $started, evidence: $result->took);
        } elseif ($sent === self::FAILED && $result->took) {
            $this->blame($collector, $left[0]);
        } elseif ($sent === self::FAILED) {
            $this->unproven[] = $left[0];
        }

        return $sent === self::STOPPED ? self::STOPPED : self::PARTIAL;
    }

    /**
     * Find the trees the destination fails on among the given trees, which just failed together, and get whether the drain goes on.
     *
     * With evidence, the trees failed while the other half of a split
     * above them went through in full: the destination was up, so the
     * trees they fail on alone are to blame.
     *
     * @param  list<array-key>  $ids
     */
    protected function isolate(Collector $collector, Exporter $exporter, Cache $cache, array $ids, int|float $started, bool $evidence): string
    {
        if (count($ids) === 1) {
            // A tree alone in its claim: nothing tells it from a failing destination.
            $this->unproven[] = $ids[0];

            return self::SENT;
        }

        $half = intdiv(count($ids) + 1, 2);
        $halves = [array_slice($ids, 0, $half), array_slice($ids, $half)];
        $sent = [];

        foreach ($halves as $index => $group) {
            $sent[$index] = $this->send($collector, $exporter, $cache, $group, $started, searching: true);

            if ($sent[$index] === self::STOPPED) {
                return self::STOPPED;
            }
        }

        foreach ($halves as $index => $group) {
            if ($sent[$index] !== self::FAILED) {
                continue;
            }

            $proven = $evidence || $sent[1 - $index] === self::SENT;

            if (count($group) > 1) {
                if ($this->isolate($collector, $exporter, $cache, $group, $started, $proven) === self::STOPPED) {
                    return self::STOPPED;
                }
            } elseif ($proven) {
                $this->blame($collector, $group[0]);
            } else {
                $this->unproven[] = $group[0];
            }
        }

        return self::SENT;
    }

    /**
     * Ack the given trees, and close them.
     *
     * @param  list<array-key>  $ids
     */
    protected function ack(Collector $collector, array $ids): void
    {
        if ($ids !== []) {
            $collector->ack($ids);
        }

        foreach ($ids as $id) {
            unset($this->open[$id]);
        }
    }

    /**
     * Get the ids of the current claim that are still open, in claim order.
     *
     * @return list<array-key>
     */
    protected function openIds(): array
    {
        return array_keys($this->open);
    }

    /**
     * Ack the trees of the given export that it got through, store the unsent spans of a tree it cut, and get the ids it did not get through.
     *
     * The ids must be exactly the trees of that export, in its order: its
     * done count and unsent spans are about them.
     *
     * A tree cut part way through its requests is acked too, and only its
     * unsent spans are stored again, as a tree of their own: sending it
     * whole again would restart from its first request every time, so a
     * tree too slow to send within one drain would never finish. The tree
     * is acked only once its unsent spans are stored: when the store
     * drops them (it is full) or fails, the whole tree is set aside for
     * ASIDE_DELAY and sent again then, so no span is lost, and the spans
     * already sent are not sent again in this drain.
     *
     * @param  list<array-key>  $ids
     * @return list<array-key>
     */
    protected function settle(Collector $collector, array $ids, ExportResult $result): array
    {
        $done = array_slice($ids, 0, $result->done);
        $rest = array_slice($ids, $result->done);

        if ($result->rest !== [] && $rest !== []) {
            $cut = array_shift($rest);

            try {
                $stored = $collector->push($result->rest);
            } catch (Throwable $e) {
                $stored = false;

                Diagnostics::warn('collect.error', 'The unsent spans of a tree could not be stored ('.$e::class.'). The whole tree will be sent again.');
            }

            if ($stored) {
                $done[] = $cut;
            } else {
                $this->release($collector, [$cut], self::ASIDE_DELAY);
            }
        }

        $this->ack($collector, $done);

        return $rest;
    }

    /**
     * Give the given trees back, unless there are none, and close them.
     *
     * @param  list<array-key>  $ids
     */
    protected function release(Collector $collector, array $ids, int $delay, bool $failed = false): void
    {
        if ($ids !== []) {
            $collector->release($ids, $delay, $failed);
        }

        foreach ($ids as $id) {
            unset($this->open[$id]);
        }
    }

    /**
     * Get the given trees, in the given order.
     *
     * @param  array<array-key, list<array<string, mixed>>>  $trees
     * @param  list<array-key>  $ids
     * @return list<list<array<string, mixed>>>
     */
    protected function only(array $trees, array $ids): array
    {
        return array_map(fn (int|string $id) => $trees[$id], $ids);
    }

    /**
     * Count a failure of a tree sent alone while the other half of its split went through: give it back for later, or drop it once it failed DROP_AFTER times.
     */
    protected function blame(Collector $collector, int|string $id): void
    {
        try {
            $attempts = $collector->attempts([$id])[$id] ?? 0;
        } catch (Throwable) {
            $attempts = 0;
        }

        if ($attempts + 1 >= self::DROP_AFTER) {
            $this->ack($collector, [$id]);

            Diagnostics::dropped('poison', 1, 'drain.poison', 'A run tree kept failing while the destination took the trees sent next to it, and was dropped after '.self::DROP_AFTER.' failed exports alone.');

            return;
        }

        $this->release($collector, [$id], self::ASIDE_DELAY, failed: true);
    }

    /**
     * Get the seconds since the given monotonic time.
     */
    protected function elapsed(int|float $since): float
    {
        return (hrtime(true) - $since) / 1_000_000_000;
    }

    /**
     * Export the given trees. An exporter that throws drops them, so a tree that breaks it is never tried again.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    protected function export(Exporter $exporter, array $trees, float $within): ExportResult
    {
        try {
            $result = $exporter->export($trees, max(0.0, $within));
        } catch (Throwable $e) {
            Stats::failed();
            Diagnostics::dropped('error', count($trees), 'export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return ExportResult::rejected();
        }

        if ($result->isOk() || $result->isRejected() || $result->done > 0 || $result->rest !== []) {
            // The destination answered: the failures in a row start over.
            $this->failuresInRow = 0;
        }

        if ($result->took) {
            $this->delivered = true;
            $this->claimDelivered = true;
            Stats::exported();
        }

        if (($refused = $result->refusedOf(count($trees))) > 0) {
            // The exporter warned with the destination's answer.
            Diagnostics::dropped('rejected', $refused);
        }

        if ($result->isRejected() || $result->isRetryable()) {
            Stats::failed();
        }

        return $result;
    }

    /**
     * Give back every tree of the claim still open, counting nothing, record the backoff for every drain, and dispatch the drain that tries them again.
     */
    protected function retryLater(Collector $collector, Cache $cache, ExportResult $result): void
    {
        $delay = $this->failureDelay($cache, $result);

        $this->release($collector, $this->openIds(), $delay);

        Diagnostics::warn('drain.retry', "Spans could not be exported: the destination could not be reached or answered 408, 429 or 5xx. They are tried again in {$delay} seconds.");

        $this->next($delay);
    }

    /**
     * Record one more failed export in a row for every drain, and get the seconds to wait before the next try.
     *
     * The wait is the backoff of the failures in a row, or the Retry-After
     * when it is longer: Retry-After means "not before".
     */
    protected function failureDelay(Cache $cache, ExportResult $result): int
    {
        // A drain that delivered something starts the failures in a row over.
        $failures = $this->delivered ? 1 : $this->backoffState($cache)[1] + 1;

        $delay = min(self::MAX_BACKOFF, max($result->retryAfter ?? 0, self::BACKOFF[min($failures, count(self::BACKOFF)) - 1]));

        $cache->put(self::BACKOFF_KEY, ['until' => $this->currentTime() + $delay, 'failures' => $failures], $delay + self::LOCK_MARGIN);

        return $delay;
    }

    /**
     * Get when the drain may export again after failed exports, as a Unix time, or null when it is not backing off.
     */
    public static function backoffUntil(Cache $cache): ?int
    {
        $backoff = $cache->get(self::BACKOFF_KEY);
        $until = is_array($backoff) ? ($backoff['until'] ?? null) : null;

        return is_int($until) && $until > Carbon::now()->getTimestamp() ? $until : null;
    }

    /**
     * Get when the next export may be tried, as a Unix time, and the failed exports in a row.
     *
     * @return array{int, int}
     */
    protected function backoffState(Cache $cache): array
    {
        $backoff = $cache->get(self::BACKOFF_KEY);

        if (! is_array($backoff) || ! is_int($backoff['until'] ?? null) || ! is_int($backoff['failures'] ?? null)) {
            return [0, 0];
        }

        return [$backoff['until'], $backoff['failures']];
    }

    /**
     * Dispatch the next drain, to run after the given seconds.
     *
     * On a sync-like queue connection the next drain would run at once,
     * inside this one: the stored trees wait for the next push instead.
     */
    protected function next(int $seconds): void
    {
        if ($this->job instanceof SyncJob) {
            Diagnostics::warn('drain.sync', 'The queue drain runs on a sync queue connection, so it cannot wait before its next try. Stored trees are sent by the drain of the next run. Use an async queue connection.');

            return;
        }

        static::dispatch($seconds);
    }
}
