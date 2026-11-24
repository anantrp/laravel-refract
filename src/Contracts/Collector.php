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
     * with one warning, so the store never grows without bound. Returns
     * whether the tree was stored, so a caller replacing a stored tree
     * keeps the old one when the new one was dropped.
     *
     * @param  list<array<string, mixed>>  $tree
     */
    public function push(array $tree): bool;

    /**
     * Claim the trees that are due, in the order they became due, keyed by their id.
     *
     * A released tree goes behind the trees that were due before it, so a
     * batch that keeps failing never blocks the others.
     *
     * Trees are added until their JSON reaches the given bytes, so the last
     * one may take the claim over. The bytes are of the decoded trees, not
     * of what the store holds, so a claim bounds the worker's memory. A tree claimed more than the given lease seconds
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
     * When a tree is given back because it failed an export of its own,
     * its failed attempts go up by one. A tree keeps its count until it is
     * acked.
     *
     * @param  list<array-key>  $ids
     */
    public function release(array $ids, int $delay, bool $failed = false): void;

    /**
     * Get the failed attempts of each of the given stored trees, keyed by id. Trees not stored are left out.
     *
     * @param  list<array-key>  $ids
     * @return array<array-key, int>
     */
    public function attempts(array $ids): array;

    /**
     * Get the number of trees in the store: waiting, claimed or released.
     */
    public function size(): int;
}
