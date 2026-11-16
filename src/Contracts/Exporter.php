<?php

namespace Anantrp\Refract\Contracts;

interface Exporter
{
    /**
     * Export the given finished run trees, each a list of neutral spans, to the destination.
     *
     * Given the most seconds the export may take, a request that cannot
     * be finished in time is not started, and the result is unfinished.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    public function export(array $trees, ?float $within = null): ExportResult;
}
