<?php

namespace Anantrp\Refract\Export;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;

/**
 * Exports nothing, for a destination that is not configured. Its platform has already warned once.
 */
class NullExporter implements Exporter
{
    public function export(array $spans): ExportResult
    {
        return ExportResult::Ok;
    }
}
