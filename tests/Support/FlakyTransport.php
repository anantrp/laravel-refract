<?php

namespace Anantrp\Refract\Tests\Support;

use Anantrp\Refract\Contracts\Transport;
use LogicException;

/**
 * Throws while it is told to, else keeps every neutral span it is sent.
 */
class FlakyTransport implements Transport
{
    /**
     * Whether the next send throws.
     */
    public bool $throws = true;

    /**
     * The neutral spans sent while it did not throw.
     *
     * @var list<array<string, mixed>>
     */
    public array $spans = [];

    public function send(array $spans): void
    {
        if ($this->throws) {
            throw new LogicException('Refract bug in the transport, SECRET');
        }

        array_push($this->spans, ...$spans);
    }
}
