<?php

namespace Anantrp\Refract\Tests\Support;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use LogicException;

/**
 * Keeps every run tree it is given, for tests that read Capture's output, or throws while it is told to.
 */
class MemoryExporter implements Exporter
{
    /**
     * Whether the next export throws.
     */
    public bool $throws = false;

    /**
     * The run trees exported so far, one export after another.
     *
     * @var list<list<array<string, mixed>>>
     */
    public array $trees = [];

    /**
     * The neutral spans of every run tree exported so far.
     *
     * @var list<array<string, mixed>>
     */
    public array $spans = [];

    public function export(array $trees): ExportResult
    {
        if ($this->throws) {
            throw new LogicException('Refract bug in the exporter, SECRET');
        }

        foreach ($trees as $tree) {
            $this->trees[] = $tree;

            array_push($this->spans, ...$tree);
        }

        return ExportResult::Ok;
    }
}
