<?php

namespace Anantrp\Refract\Contracts;

interface Exporter
{
    /**
     * Export the given finished run trees, each a list of neutral spans, to the destination.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    public function export(array $trees): ExportResult;
}
