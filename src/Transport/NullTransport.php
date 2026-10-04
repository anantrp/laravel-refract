<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Transport;

/**
 * Discards every batch.
 */
class NullTransport implements Transport
{
    public function send(array $spans): void
    {
        //
    }
}
