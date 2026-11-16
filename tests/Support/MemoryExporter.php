<?php

namespace Anantrp\Refract\Tests\Support;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use LogicException;

/**
 * Keeps every run tree it is given, for tests that read Capture's output,
 * or answers with the results it is told to, or throws while it is told to.
 */
class MemoryExporter implements Exporter
{
    /**
     * Whether the next export throws.
     */
    public bool $throws = false;

    /**
     * The results of the next exports, in order. Once they run out, every export is ok.
     *
     * @var list<ExportResult>
     */
    public array $results = [];

    /**
     * The number of exports so far, whatever their result.
     */
    public int $exports = 0;

    /**
     * The run trees exported with an ok result so far, one export after another.
     *
     * @var list<list<array<string, mixed>>>
     */
    public array $trees = [];

    /**
     * The neutral spans of every run tree exported with an ok result so far.
     *
     * @var list<array<string, mixed>>
     */
    public array $spans = [];

    public function export(array $trees): ExportResult
    {
        $this->exports++;

        if ($this->throws) {
            throw new LogicException('Refract bug in the exporter, SECRET');
        }

        $result = array_shift($this->results) ?? ExportResult::ok();

        if (! $result->isOk()) {
            return $result;
        }

        foreach ($trees as $tree) {
            $this->trees[] = $tree;

            array_push($this->spans, ...$tree);
        }

        return $result;
    }
}
