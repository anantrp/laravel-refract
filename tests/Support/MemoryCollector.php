<?php

namespace Anantrp\Refract\Tests\Support;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * Keeps run trees in memory, with leases and delays on the app's clock, so a test can travel in time.
 */
class MemoryCollector implements Collector
{
    /**
     * Whether every call throws, as a store that cannot be reached would.
     */
    public bool $throws = false;

    /**
     * Whether only push() throws, as a store that refuses a write would.
     */
    public bool $throwOnPush = false;

    /**
     * The stored trees by id: the tree, when it may be claimed, and when it was claimed.
     *
     * @var array<int, array{tree: list<array<string, mixed>>, available_at: int, claimed_at: ?int, attempts?: int}>
     */
    public array $entries = [];

    /**
     * Every release so far: the ids and the delay.
     *
     * @var list<array{list<array-key>, int}>
     */
    public array $releases = [];

    /**
     * The id the next tree is stored under.
     */
    protected int $next = 1;

    public function __construct(public int $maxTrees = PHP_INT_MAX) {}

    public function push(array $tree): bool
    {
        $this->failIfTold();

        if ($this->throwOnPush) {
            throw new LogicException('The store refused the write.');
        }

        if (count($this->entries) >= $this->maxTrees) {
            Diagnostics::warn('collect.full', 'The trace store is full: new run trees are dropped.');

            return false;
        }

        $this->entries[$this->next++] = ['tree' => $tree, 'available_at' => Date::now()->getTimestamp(), 'claimed_at' => null];

        return true;
    }

    public function claim(int $maxBytes, int $lease): array
    {
        $this->failIfTold();

        $now = Date::now()->getTimestamp();
        $trees = [];
        $used = 0;

        // In the order they became due, as the real collectors claim them.
        $due = $this->entries;
        uksort($due, fn (int $one, int $other) => [$due[$one]['available_at'], $one] <=> [$due[$other]['available_at'], $other]);

        foreach ($due as $id => $entry) {
            if ($used >= $maxBytes) {
                break;
            }

            if ($entry['available_at'] > $now || ($entry['claimed_at'] !== null && $now < $entry['claimed_at'] + $lease)) {
                continue;
            }

            $this->entries[$id]['claimed_at'] = $now;
            $trees[$id] = $entry['tree'];
            // Sized as the real collectors claim it, by its JSON.
            $used += strlen((string) json_encode($entry['tree']));
        }

        return $trees;
    }

    public function ack(array $ids): void
    {
        $this->failIfTold();

        foreach ($ids as $id) {
            unset($this->entries[$id]);
        }
    }

    public function release(array $ids, int $delay, bool $failed = false): void
    {
        $this->failIfTold();

        $this->releases[] = [$ids, $delay];

        foreach ($ids as $id) {
            if (isset($this->entries[$id])) {
                $this->entries[$id]['available_at'] = Date::now()->getTimestamp() + $delay;
                $this->entries[$id]['claimed_at'] = null;
                $this->entries[$id]['attempts'] = ($this->entries[$id]['attempts'] ?? 0) + ($failed ? 1 : 0);
            }
        }
    }

    public function attempts(array $ids): array
    {
        $this->failIfTold();

        $attempts = [];

        foreach ($ids as $id) {
            if (isset($this->entries[$id])) {
                $attempts[$id] = $this->entries[$id]['attempts'] ?? 0;
            }
        }

        return $attempts;
    }

    public function size(): int
    {
        $this->failIfTold();

        return count($this->entries);
    }

    /**
     * Throw while the collector is told to.
     */
    protected function failIfTold(): void
    {
        if ($this->throws) {
            throw new LogicException('Refract bug in the collector, SECRET');
        }
    }
}
