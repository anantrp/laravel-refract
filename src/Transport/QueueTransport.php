<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory;
use Throwable;

/**
 * Exports each batch from a queue worker, on the app's default queue connection.
 *
 * A job is dispatched only on an async driver in the allow-list. Every
 * other driver (sync, deferred, background, failover, custom), a batch too
 * big for the queue, or a failed dispatch exports in this process instead.
 */
class QueueTransport implements Transport
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
     * Create a new queue transport instance.
     */
    public function __construct(protected Container $container, protected Exporter $exporter) {}

    public function send(array $spans): void
    {
        $connection = config('queue.default');
        $driver = is_string($connection) ? config("queue.connections.{$connection}.driver") : null;

        if (! is_string($connection) || ! in_array($driver, self::ASYNC_DRIVERS, true)) {
            $this->exporter->export($spans);

            return;
        }

        $job = ExportSpans::of($spans);

        if ($job === null || strlen((string) json_encode(serialize($job))) > self::MAX_PAYLOAD - self::ENVELOPE) {
            Diagnostics::warn('queue.too_big', 'A batch of spans is too big for the queue. It was exported in this process.');

            $this->exporter->export($spans);

            return;
        }

        try {
            $this->container->make(Factory::class)->connection($connection)->push($job);
        } catch (Throwable $e) {
            Diagnostics::warn('queue.dispatch', 'A batch of spans could not be queued ('.$e::class.'). It was exported in this process.');

            $this->exporter->export($spans);
        }
    }
}
