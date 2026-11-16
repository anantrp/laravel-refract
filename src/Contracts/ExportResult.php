<?php

namespace Anantrp\Refract\Contracts;

/**
 * What became of an export: delivered, worth trying again later, refused for good, or cut short by its time limit.
 *
 * A retryable result may carry the seconds the destination asked to wait
 * (its Retry-After), already capped by the exporter. An unfinished result
 * carries the number of leading trees that were done (taken or refused)
 * before time ran out; the rest were not sent in full.
 */
final readonly class ExportResult
{
    /**
     * Create a new export result instance.
     */
    private function __construct(
        protected string $status,
        public ?int $retryAfter = null,
        public int $done = 0,
    ) {}

    /**
     * The destination took the trees.
     */
    public static function ok(): self
    {
        return new self('ok');
    }

    /**
     * The destination could not take the trees now: a network error, 408, 429 or 5xx.
     */
    public static function retryable(?int $retryAfter = null): self
    {
        return new self('retryable', $retryAfter);
    }

    /**
     * The destination refused the trees, or they could not be sent: trying again would not help.
     */
    public static function rejected(): self
    {
        return new self('rejected');
    }

    /**
     * Time ran out before every tree was sent: the given number of leading trees were done, the rest may be sent again.
     */
    public static function unfinished(int $done): self
    {
        return new self('unfinished', done: $done);
    }

    /**
     * Determine if the destination took the trees.
     */
    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    /**
     * Determine if the trees may be sent again later.
     */
    public function isRetryable(): bool
    {
        return $this->status === 'retryable';
    }

    /**
     * Determine if time ran out before every tree was sent.
     */
    public function isUnfinished(): bool
    {
        return $this->status === 'unfinished';
    }

    /**
     * Determine if the trees were refused for good.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
