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
 * A driver outside the allow-list, a batch too big for the queue, or a
 * failed dispatch exports in this process instead. A send while the
 * process keeps going never writes to a database queue, because a rollback
 * of the app's transaction would remove the job. Each part is its own job,
 * so a retry never sends a part again that already got through.
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
        $this->inProcess->startSend();

        return $this->sendParts($spans, true, fn (array $part) => $this->inProcess->exportNow($part));
    }

    /**
     * Get the longest wait in seconds the destination asked for across the parts exported in this process in the last send, or null.
     */
    public function retryAfter(): ?int
    {
        return $this->inProcess->retryAfter();
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
