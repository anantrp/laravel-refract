<?php

namespace Anantrp\Refract\Transport\Collectors;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;
use PDO;
use stdClass;

/**
 * Collects run trees in the "refract_traces" table, one row per tree.
 *
 * A claim locks the due rows with SELECT ... FOR UPDATE SKIP LOCKED on
 * MySQL 8+, MariaDB 10.6+ and PostgreSQL, so two drains never claim the
 * same tree; on SQLite, which has no SKIP LOCKED, the claim is a plain
 * transaction. A claimed row is marked with the time it was reserved, and
 * is due again once its lease has run out. An ack deletes the rows; a
 * release clears the reservation and makes them due after a delay.
 *
 * Due rows are claimed in the order they became due (available_at, then
 * id), so a released tree waits behind the trees that were due before it,
 * and one batch the destination keeps failing never blocks the rest.
 *
 * The cap is on the bytes stored, checked before each insert: at the cap
 * a new tree is dropped and counted, and the stored trees are kept. The
 * sum reads an index on the bytes column, never the payloads, and the cap
 * bounds the rows it reads; a running total would drift on a manual delete
 * or a crash between two writes.
 *
 * A push never runs inside an open transaction on the collector's
 * connection: it is skipped with one warning. Joined to the app's
 * transaction, the tree would be rolled back with it, and stay unseen by
 * the drain until it commits; and a failed insert or a deadlock there
 * would abort or roll back the app's own work. Give the collector a
 * connection of its own.
 */
class DatabaseCollector implements Collector
{
    use InteractsWithTime;

    /**
     * The table the trees are stored in, created by the published migration.
     */
    public const TABLE = 'refract_traces';

    /**
     * The most bytes of tree JSON the store holds.
     */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * The most trees one claim returns, so one claim never reads the whole table.
     */
    protected const CLAIM_LIMIT = 1_000;

    /**
     * The times a claim is tried when the database reports a deadlock or a locked database.
     */
    protected const CLAIM_ATTEMPTS = 3;

    /**
     * The lock a claim reads due rows with, once known.
     */
    protected string|bool|null $lockForClaim = null;

    /**
     * Create a new database collector instance.
     */
    public function __construct(
        protected Connection $database,
        protected int $maxBytes = self::MAX_BYTES,
    ) {}

    public function push(array $tree): bool
    {
        if ($this->database->transactionLevel() > 0) {
            Diagnostics::dropped('transaction', 1, 'collect.transaction', "A run tree ended inside an open database transaction on the trace store's connection [{$this->database->getName()}], and was dropped so it cannot affect that transaction. Give the database collector a connection of its own.");

            return false;
        }

        $payload = TreePayload::encode($tree);

        if ($payload === null) {
            Diagnostics::dropped('encode', 1, 'collect.encode', 'A run tree could not be encoded for the store and was dropped.');

            return false;
        }

        // Two pushes at once may both pass the check: the cap is exact to within one tree per process.
        if ($this->storedBytes() + strlen($payload) > $this->maxBytes) {
            Diagnostics::dropped('full', 1, 'collect.full', "The trace store is full ({$this->maxBytes} bytes): new run trees are dropped until the queue drain catches up.");

            return false;
        }

        $this->table()->insert([
            'payload' => $payload,
            'bytes' => strlen($payload),
            'available_at' => $this->currentTime(),
            'reserved_at' => null,
        ]);

        return true;
    }

    public function claim(int $maxBytes, int $lease): array
    {
        $ids = $this->database->transaction(fn () => $this->reserve(max(1, $maxBytes), max(0, $lease)), self::CLAIM_ATTEMPTS);

        if ($ids === []) {
            return [];
        }

        $trees = [];
        $broken = [];

        foreach ($this->table()->whereIn('id', $ids)->orderBy('available_at')->orderBy('id')->get(['id', 'payload']) as $row) {
            $id = $this->number($row->id);
            $tree = TreePayload::decode(is_string($row->payload) ? $row->payload : '');

            if ($tree === null) {
                $broken[] = $id;
            } else {
                $trees[$id] = $tree;
            }
        }

        if ($broken !== []) {
            Diagnostics::dropped('decode', count($broken), 'collect.decode', 'A stored run tree could not be decoded and was dropped.');

            $this->ack($broken);
        }

        return $trees;
    }

    public function ack(array $ids): void
    {
        $ids = $this->rowIds($ids);

        if ($ids !== []) {
            $this->table()->whereIn('id', $ids)->delete();
        }
    }

    public function release(array $ids, int $delay): void
    {
        $ids = $this->rowIds($ids);

        if ($ids !== []) {
            $this->table()->whereIn('id', $ids)->update([
                'available_at' => $this->availableAt(max(0, $delay)),
                'reserved_at' => null,
            ]);
        }
    }

    public function size(): int
    {
        return $this->table()->count();
    }

    /**
     * Reserve the due rows, in the order they became due, until they reach the given bytes, and get their ids.
     *
     * The rows that fit are picked without a lock, then locked and checked
     * again: only the rows a claim takes are locked, so a claim running at
     * the same time skips just those, and a row another claim took between
     * the two reads is left out. Runs in the claim's transaction, so the
     * rows stay locked until they are marked reserved.
     *
     * @return list<int>
     */
    protected function reserve(int $maxBytes, int $lease): array
    {
        $now = $this->currentTime();

        $picked = $this->fitting($this->due($now, $lease)->limit(self::CLAIM_LIMIT)->get(['id', 'bytes']), $maxBytes);

        if ($picked === []) {
            return [];
        }

        $locked = $this->due($now, $lease)
            ->whereIn('id', $picked)
            ->lock($this->lockForClaim())
            ->pluck('id');

        $ids = array_values(array_map($this->number(...), $locked->all()));

        if ($ids !== []) {
            $this->table()->whereIn('id', $ids)->update(['reserved_at' => $now]);
        }

        return $ids;
    }

    /**
     * Get a query on the rows that may be claimed now, in the order they became due: never claimed or released and due, or claimed and their lease ran out.
     */
    protected function due(int $now, int $lease): Builder
    {
        return $this->table()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('reserved_at')->where('available_at', '<=', $now))
                ->orWhere('reserved_at', '<=', $now - $lease))
            ->orderBy('available_at')
            ->orderBy('id');
    }

    /**
     * Get the ids of the given rows, in order, until their bytes reach the given bytes. The last row may take them over.
     *
     * @param  iterable<stdClass>  $rows
     * @return list<int>
     */
    protected function fitting(iterable $rows, int $maxBytes): array
    {
        $ids = [];
        $used = 0;

        foreach ($rows as $row) {
            if ($used >= $maxBytes) {
                break;
            }

            $ids[] = $this->number($row->id);
            $used += $this->number($row->bytes);
        }

        return $ids;
    }

    /**
     * Get the bytes of every stored tree: waiting, claimed or released.
     */
    protected function storedBytes(): int
    {
        return $this->number($this->table()->sum('bytes'));
    }

    /**
     * Get the given column value as a whole number. Drivers give numbers as strings or ints.
     */
    protected function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Get the given ids that are row ids. Any other id matches no row.
     *
     * @param  list<array-key>  $ids
     * @return list<int>
     */
    protected function rowIds(array $ids): array
    {
        return array_values(array_map(intval(...), array_filter(
            $ids,
            fn (int|string $id) => is_int($id) ? $id > 0 : ctype_digit($id),
        )));
    }

    /**
     * Get the lock that keeps two claims from reading the same due rows.
     *
     * SKIP LOCKED lets a second claim pass over the rows the first one
     * holds instead of waiting for them. Where it is not supported, the
     * claim locks the rows it reads (SQLite: the whole database, for writes).
     */
    protected function lockForClaim(): string|bool
    {
        if ($this->lockForClaim !== null) {
            return $this->lockForClaim;
        }

        $pdo = $this->database->getPdo();
        $engine = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $version = is_string($version) ? $version : '';

        if (Str::contains($version, 'MariaDB')) {
            $engine = 'mariadb';
            $version = Str::before(Str::after($version, '5.5.5-'), '-');
        }

        $skipLocked = ($engine === 'mysql' && version_compare($version, '8.0.1', '>='))
            || ($engine === 'mariadb' && version_compare($version, '10.6.0', '>='))
            || ($engine === 'pgsql' && version_compare($version, '9.5', '>='));

        return $this->lockForClaim = $skipLocked ? 'FOR UPDATE SKIP LOCKED' : true;
    }

    /**
     * Get a query on the table of trees.
     */
    protected function table(): Builder
    {
        return $this->database->table(self::TABLE);
    }
}
