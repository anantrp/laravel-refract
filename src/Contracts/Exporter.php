<?php

namespace Anantrp\Refract\Contracts;

interface Exporter
{
    /**
     * Export the given neutral spans to the destination.
     *
     * @param  list<array<string, mixed>>  $spans
     */
    public function export(array $spans): ExportResult;
}
