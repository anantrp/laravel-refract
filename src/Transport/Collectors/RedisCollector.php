<?php

namespace Anantrp\Refract\Transport\Collectors;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\ReportsStats;
use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use RuntimeException;

/**
 * Collects run trees in a Redis stream, read by one consumer group.
 *
 * A claim reads new entries and takes over the entries whose lease ran
 * out (XAUTOCLAIM), so a crashed worker's trees are claimed again. An ack
 * deletes the entry. A release moves the tree out of the stream into a
 * sorted set of delayed trees, and the first claim after it is due adds
 * it back: a stream has no delayed visibility of its own.
 *
 * The cap is on the bytes stored, checked before each add: at the cap a
 * new tree is dropped and counted, and the trees already stored, leased
 * ones included, are kept. XTRIM MAXLEN would drop the oldest trees
 * instead, and counts entries, not bytes. Every step is one Lua script,
 * so the byte count stays exact with many workers and both Redis clients.
 *
 * The keys share the "{traces}" hash tag, so they live on one node of a
 * Redis Cluster. Use a connection that never evicts keys: an eviction
 * policy would drop trees, or the byte count, without a warning.
 */
class RedisCollector implements Collector, ReportsStats
{
    /**
     * The key of the stream; the other keys add a suffix to it.
     */
    public const KEY = 'refract:{traces}';

    /**
     * The most bytes of tree JSON the store holds.
     */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * The most trees one claim returns, so one script never runs long.
     */
    protected const CLAIM_LIMIT = 1_000;

    /**
     * The consumer group every drain reads with, and its one consumer: leases are kept by idle time, not by consumer.
     */
    protected const GROUP = 'refract';

    protected const CONSUMER = 'drain';

    /**
     * Add a tree unless it would take the store over its cap. Resets the byte count when the store is empty.
     */
    protected const PUSH = <<<'LUA'
        if redis.call('xlen', KEYS[1]) == 0 and redis.call('zcard', KEYS[4]) == 0 then
            redis.call('set', KEYS[2], 0)
        end

        local size = string.len(ARGV[1])

        if tonumber(redis.call('get', KEYS[2]) or '0') + size > tonumber(ARGV[2]) then
            redis.call('incr', KEYS[3])

            return 0
        end

        redis.call('xadd', KEYS[1], '*', 'tree', ARGV[1])
        redis.call('incrby', KEYS[2], size)

        return 1
        LUA;

    /**
     * Add back the delayed trees that are due, then take over expired leases and read new entries, until the bytes are reached.
     */
    protected const CLAIM = <<<'LUA'
        local time = redis.call('time')
        local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
        local maxBytes, limit = tonumber(ARGV[1]), tonumber(ARGV[5])

        pcall(redis.call, 'xgroup', 'create', KEYS[1], ARGV[3], '0', 'MKSTREAM')

        for _, id in ipairs(redis.call('zrangebyscore', KEYS[4], '-inf', now, 'LIMIT', 0, limit)) do
            local tree = redis.call('hget', KEYS[5], id)

            if tree then
                local added = redis.call('xadd', KEYS[1], '*', 'tree', tree)
                local attempts = redis.call('hget', KEYS[6], id)

                if attempts then
                    redis.call('hset', KEYS[6], added, attempts)
                end
            end

            redis.call('zrem', KEYS[4], id)
            redis.call('hdel', KEYS[5], id)
            redis.call('hdel', KEYS[6], id)
        end

        local claimed, used = {}, 0

        -- An entry with no tree is returned empty, so the caller acks it instead of claiming it forever.
        local function take(entry)
            if not entry then
                return
            end

            local tree = ''

            for i = 1, #entry[2], 2 do
                if entry[2][i] == 'tree' then
                    tree = entry[2][i + 1]
                end
            end

            table.insert(claimed, entry[1])
            table.insert(claimed, tree)
            used = used + string.len(tree)
        end

        local function full()
            return used >= maxBytes or #claimed / 2 >= limit
        end

        local cursor = '0-0'

        repeat
            local result = redis.call('xautoclaim', KEYS[1], ARGV[3], ARGV[4], ARGV[2], cursor, 'COUNT', 1)

            cursor = result[1]

            for _, entry in ipairs(result[2]) do
                take(entry)
            end
        until cursor == '0-0' or full()

        while not full() do
            local result = redis.call('xreadgroup', 'GROUP', ARGV[3], ARGV[4], 'COUNT', 1, 'STREAMS', KEYS[1], '>')

            if not result then
                break
            end

            for _, entry in ipairs(result[1][2]) do
                take(entry)
            end
        end

        return claimed
        LUA;

    /**
     * Ack and delete the given entries, and take their bytes off the count.
     */
    protected const ACK = <<<'LUA'
        for i = 2, #ARGV do
            local entry = redis.call('xrange', KEYS[1], ARGV[i], ARGV[i])

            redis.call('xack', KEYS[1], ARGV[1], ARGV[i])
            redis.call('hdel', KEYS[6], ARGV[i])

            if #entry > 0 and redis.call('xdel', KEYS[1], ARGV[i]) == 1 then
                for j = 1, #entry[1][2], 2 do
                    if entry[1][2][j] == 'tree' and redis.call('decrby', KEYS[2], string.len(entry[1][2][j + 1])) < 0 then
                        redis.call('set', KEYS[2], 0)
                    end
                end
            end
        end

        return 1
        LUA;

    /**
     * Move the given entries out of the stream into the delayed trees, due after the given milliseconds, adding a failed attempt when told to. Their bytes stay counted.
     */
    protected const RELEASE = <<<'LUA'
        local time = redis.call('time')
        local due = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000) + tonumber(ARGV[2])

        for i = 4, #ARGV do
            local entry = redis.call('xrange', KEYS[1], ARGV[i], ARGV[i])

            redis.call('xack', KEYS[1], ARGV[1], ARGV[i])

            if #entry > 0 then
                if ARGV[3] == '1' then
                    redis.call('hincrby', KEYS[6], ARGV[i], 1)
                end

                for j = 1, #entry[1][2], 2 do
                    if entry[1][2][j] == 'tree' then
                        redis.call('hset', KEYS[5], ARGV[i], entry[1][2][j + 1])
                        redis.call('zadd', KEYS[4], due, ARGV[i])
                    end
                end

                redis.call('xdel', KEYS[1], ARGV[i])
            end
        end

        return 1
        LUA;

    /**
     * Count the trees in the stream and the delayed trees.
     */
    protected const SIZE = <<<'LUA'
        return redis.call('xlen', KEYS[1]) + redis.call('zcard', KEYS[4])
        LUA;

    /**
     * Read the failed attempts of the given entries that are stored, in the stream or delayed, as id and count pairs.
     */
    protected const ATTEMPTS = <<<'LUA'
        local found = {}

        for i = 1, #ARGV do
            if #redis.call('xrange', KEYS[1], ARGV[i], ARGV[i]) > 0 or redis.call('hexists', KEYS[5], ARGV[i]) == 1 then
                table.insert(found, ARGV[i])
                table.insert(found, tonumber(redis.call('hget', KEYS[6], ARGV[i]) or '0'))
            end
        end

        return found
        LUA;

    /**
     * Read the number of trees dropped at the cap.
     */
    protected const DROPPED = <<<'LUA'
        return tonumber(redis.call('get', KEYS[3]) or '0')
        LUA;

    /**
     * Read the trees, their bytes, the milliseconds the oldest due tree has waited (-1 when none is due), the trees dropped at the cap, and the most failed attempts of a tree.
     *
     * A tree in the stream became due when it was added, at its id's time.
     * A delayed tree is due at its score.
     */
    protected const STATS = <<<'LUA'
        local time = redis.call('time')
        local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
        local oldest = nil

        local first = redis.call('xrange', KEYS[1], '-', '+', 'COUNT', 1)

        if #first > 0 then
            oldest = tonumber(string.match(first[1][1], '^(%d+)-'))
        end

        local due = redis.call('zrangebyscore', KEYS[4], '-inf', now, 'WITHSCORES', 'LIMIT', 0, 1)

        if #due > 0 and (oldest == nil or tonumber(due[2]) < oldest) then
            oldest = tonumber(due[2])
        end

        local most = 0

        for _, attempts in ipairs(redis.call('hvals', KEYS[6])) do
            most = math.max(most, tonumber(attempts))
        end

        return {
            redis.call('xlen', KEYS[1]) + redis.call('zcard', KEYS[4]),
            tonumber(redis.call('get', KEYS[2]) or '0'),
            oldest == nil and -1 or math.max(0, now - oldest),
            tonumber(redis.call('get', KEYS[3]) or '0'),
            most,
        }
        LUA;

    /**
     * Create a new Redis collector instance.
     */
    public function __construct(
        protected Connection $redis,
        protected string $key = self::KEY,
        protected int $maxBytes = self::MAX_BYTES,
    ) {}

    public function push(array $tree): bool
    {
        $payload = TreePayload::encode($tree);

        if ($payload === null) {
            Diagnostics::dropped('encode', 1, 'collect.encode', 'A run tree could not be encoded for the store and was dropped.');

            return false;
        }

        if ($this->run(self::PUSH, $payload, $this->maxBytes) === 0) {
            Diagnostics::dropped('full', 1, 'collect.full', "The trace store is full ({$this->maxBytes} bytes): new run trees are dropped until the queue drain catches up.");

            return false;
        }

        return true;
    }

    public function claim(int $maxBytes, int $lease): array
    {
        $claimed = $this->run(self::CLAIM, max(1, $maxBytes), max(0, $lease) * 1000, self::GROUP, self::CONSUMER, self::CLAIM_LIMIT);

        $trees = [];
        $broken = [];

        foreach (array_chunk(is_array($claimed) ? $claimed : [], 2) as $entry) {
            $id = is_string($entry[0] ?? null) ? $entry[0] : '';
            $tree = TreePayload::decode(is_string($entry[1] ?? null) ? $entry[1] : '');

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
        $ids = $this->entryIds($ids);

        if ($ids !== []) {
            $this->run(self::ACK, self::GROUP, ...$ids);
        }
    }

    public function release(array $ids, int $delay, bool $failed = false): void
    {
        $ids = $this->entryIds($ids);

        if ($ids !== []) {
            $this->run(self::RELEASE, self::GROUP, max(0, $delay) * 1000, $failed ? 1 : 0, ...$ids);
        }
    }

    public function attempts(array $ids): array
    {
        $ids = $this->entryIds($ids);

        if ($ids === []) {
            return [];
        }

        $found = $this->run(self::ATTEMPTS, ...$ids);
        $attempts = [];

        foreach (array_chunk(is_array($found) ? $found : [], 2) as [$id, $count]) {
            if (is_string($id)) {
                $attempts[$id] = is_numeric($count) ? (int) $count : 0;
            }
        }

        return $attempts;
    }

    public function size(): int
    {
        return $this->count(self::SIZE);
    }

    public function stats(): array
    {
        $stats = $this->run(self::STATS);
        [$trees, $bytes, $age, $dropped, $attempts] = array_map(fn (mixed $value) => is_numeric($value) ? (int) $value : 0, is_array($stats) ? array_pad($stats, 5, 0) : [0, 0, -1, 0, 0]);

        return [
            'trees' => $trees,
            'bytes' => $bytes,
            'max_bytes' => $this->maxBytes,
            'oldest_age' => $age < 0 ? null : intdiv($age, 1000),
            'dropped' => $dropped,
            'max_attempts' => $attempts,
        ];
    }

    /**
     * Get the number of trees dropped at the cap, since the store was created.
     */
    public function dropped(): int
    {
        return $this->count(self::DROPPED);
    }

    /**
     * Run the given script, which returns a count.
     */
    protected function count(string $script): int
    {
        $count = $this->run($script);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Get the given ids that are stream entry ids. Any other id would fail the whole script.
     *
     * @param  list<array-key>  $ids
     * @return list<string>
     */
    protected function entryIds(array $ids): array
    {
        return array_values(array_filter(
            array_map(strval(...), $ids),
            fn (string $id) => preg_match('/^\d+-\d+$/', $id) === 1,
        ));
    }

    /**
     * Run the given script on the store's keys with the given arguments.
     *
     * Both clients prefix the keys of EVAL with the connection's prefix.
     *
     * @throws RuntimeException when Redis could not run it.
     */
    protected function run(string $script, string|int ...$arguments): mixed
    {
        $keys = [$this->key, "{$this->key}:bytes", "{$this->key}:dropped", "{$this->key}:delayed", "{$this->key}:delayed:trees", "{$this->key}:attempts"];

        // phpredis takes EVAL's keys and arguments as one array, Predis as a list: PhpRedisConnection hides the difference.
        $result = $this->redis instanceof PhpRedisConnection
            ? $this->redis->eval($script, count($keys), ...$keys, ...$arguments)
            : $this->redis->command('eval', [$script, count($keys), ...$keys, ...$arguments]);

        if ($result === false || $result === null) {
            throw new RuntimeException('Redis could not run a Refract trace store script.');
        }

        return $result;
    }
}
