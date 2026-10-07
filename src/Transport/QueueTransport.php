<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\SendNow;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Support\Diagnostics;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory;
use Throwable;

/**
 * Exports each batch from a queue worker, on the app's default queue connection.
 *
 * A job is dispatched only on an async driver in the allow-list. Every
 * other driver (sync, deferred, background, failover, custom), a batch too
 * big for the queue, or a failed dispatch exports in this process instead,
 * like the sync transport: no retry.
 *
 * A send while the process keeps going (sendNow) never writes to a
 * database queue: the app may hold a transaction on that database, and a
 * rollback would remove the job. It exports in this process instead, and
 * every part exported in this process that the destination could not take
 * now is given back to be tried again later.
 *
 * A batch the exporter splits into parts is queued as one job per part,
 * so a retry never sends a part again that already got through. Each part
 * that cannot go through the queue is exported in this process on its own.
 */
class QueueTransport implements SendNow, Transport
{
    /**
     * The drivers that run jobs in a worker process.
     */
    public const ASYNC_DRIVERS = ['redis', 'database', 'sqs', 'beanstalkd'];

    /**
     * The largest queue payload in bytes (the SQS limit).
     */
    public const MAX_PAYLOAD = 262_144;

    /**
     * The bytes kept free for the job envelope the queue wraps around the batch.
     */
    public const ENVELOPE = 2_048;

    /**
     * The transport for the batches exported in this process.
     */
    protected SyncTransport $inProcess;

    /**
     * The seconds the destination asked to wait after the last part given back, or null.
     */
    protected ?int $retryAfter = null;

    /**
     * Create a new queue transport instance.
     */
    public function __construct(protected Container $container, Exporter $exporter)
    {
        $this->inProcess = new SyncTransport($exporter);
    }

    public function send(array $spans): void
    {
        $this->sendParts($spans, false, function (array $part) {
            $this->inProcess->export($part);

            return [];
        });
    }

    public function sendNow(array $spans): array
    {
        $this->retryAfter = null;

        return $this->sendParts($spans, true, function (array $part) {
            $kept = $this->inProcess->exportNow($part);

            if ($kept !== []) {
                $this->retryAfter = $this->inProcess->retryAfter();
            }

            return $kept;
        });
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Queue each part of the given batch, and get the spans the in-process export gave back.
     *
     * @param  list<array<string, mixed>>  $spans
     * @param  Closure(list<array<string, mixed>>): list<array<string, mixed>>  $inProcess
     * @return list<array<string, mixed>>
     */
    protected function sendParts(array $spans, bool $now, Closure $inProcess): array
    {
        $kept = [];

        foreach ($this->inProcess->parts($spans) as $part) {
            try {
                array_push($kept, ...$this->queue($part, $now, $inProcess));
            } catch (Throwable $e) {
                Diagnostics::warn('queue.error', 'A batch of spans could not be queued ('.$e::class.'). It was exported in this process.');

                array_push($kept, ...$inProcess($part));
            }
        }

        return $kept;
    }

    /**
     * Queue one part, or export it in this process when it cannot go through the queue.
     *
     * @param  list<array<string, mixed>>  $spans
     * @param  Closure(list<array<string, mixed>>): list<array<string, mixed>>  $inProcess
     * @return list<array<string, mixed>>
     */
    protected function queue(array $spans, bool $now, Closure $inProcess): array
    {
        $connection = config('queue.default');
        $driver = is_string($connection) ? config("queue.connections.{$connection}.driver") : null;

        if (! is_string($connection) || ! in_array($driver, self::ASYNC_DRIVERS, true) || ($now && $driver === 'database')) {
            return $inProcess($spans);
        }

        $job = ExportSpans::of($spans);

        if ($job === null || strlen((string) json_encode(serialize($job))) > self::MAX_PAYLOAD - self::ENVELOPE) {
            Diagnostics::warn('queue.too_big', 'A batch of spans is too big for the queue. It was exported in this process.');

            return $inProcess($spans);
        }

        try {
            $this->container->make(Factory::class)->connection($connection)->push($job);
        } catch (Throwable $e) {
            Diagnostics::warn('queue.dispatch', 'A batch of spans could not be queued ('.$e::class.'). It was exported in this process.');

            return $inProcess($spans);
        }

        return [];
    }
}
