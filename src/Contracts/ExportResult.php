<?php

namespace Anantrp\Refract\Contracts;

/**
 * What became of an export: delivered, worth trying again later, or refused for good.
 *
 * A retryable result may carry the seconds the destination asked to wait
 * (its Retry-After), already capped by the exporter.
 */
final readonly class ExportResult
{
    /**
     * Create a new export result instance.
     */
    private function __construct(
        protected string $status,
        public ?int $retryAfter = null,
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
     * Determine if the trees were refused for good.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
