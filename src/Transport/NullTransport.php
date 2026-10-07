<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\SendNow;
use Anantrp\Refract\Contracts\Transport;

/**
 * Discards every batch.
 */
class NullTransport implements SendNow, Transport
{
    public function send(array $spans): void
    {
        //
    }

    public function sendNow(array $spans): array
    {
        return [];
    }

    public function retryAfter(): ?int
    {
        return null;
    }
}
