<?php

namespace Anantrp\Refract\Contracts;

/**
 * A bounded store of finished run trees, waiting for the queue drain to export them.
 *
 * Delivery is at least once: a tree is claimed for a lease, then acked when
 * it was exported (or refused for good), or released to be claimed again
 * after a delay. A tree whose lease runs out, because the worker holding it
 * crashed, can be claimed again.
 */
interface Collector
{
    /**
     * Add the given finished run tree, a list of neutral spans.
     *
     * A tree that would take the store over its cap is dropped and counted,
     * with one warning, so the store never grows without bound.
     *
     * @param  list<array<string, mixed>>  $tree
     */
    public function push(array $tree): void;

    /**
     * Claim the trees that are due, in the order they became due, keyed by their id.
     *
     * A released tree goes behind the trees that were due before it, so a
     * batch that keeps failing never blocks the others.
     *
     * Trees are added until they reach the given bytes, so the last one may
     * take the claim over. A tree claimed more than the given lease seconds
     * ago, and neither acked nor released since, is claimed again.
     *
     * @return array<array-key, list<array<string, mixed>>>
     */
    public function claim(int $maxBytes, int $lease): array;

    /**
     * Remove the given claimed trees from the store.
     *
     * @param  list<array-key>  $ids
     */
    public function ack(array $ids): void;

    /**
     * Give the given claimed trees back, to be claimed again after the given seconds.
     *
     * @param  list<array-key>  $ids
     */
    public function release(array $ids, int $delay): void;

    /**
     * Get the number of trees in the store: waiting, claimed or released.
     */
    public function size(): int;
}
