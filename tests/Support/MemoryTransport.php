<?php

namespace Anantrp\Refract\Tests\Support;

use Anantrp\Refract\Contracts\Transport;

/**
 * Keeps every neutral span it is sent, for tests that read Capture's output.
 */
class MemoryTransport implements Transport
{
    /**
     * The neutral spans sent so far.
     *
     * @var list<array<string, mixed>>
     */
    public array $spans = [];

    public function send(array $spans): void
    {
        array_push($this->spans, ...$spans);
    }
}
