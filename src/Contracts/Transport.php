<?php

namespace Anantrp\Refract\Contracts;

interface Transport
{
    /**
     * Send the given batch of neutral spans on its way to the exporter.
     *
     * @param  list<array<string, mixed>>  $spans
     */
    public function send(array $spans): void;
}
