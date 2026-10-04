<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Workbench\App\Langfuse\MissingKeys;
use Workbench\App\Langfuse\TreeReader;

class Tree extends Command
{
    protected $signature = 'refract:tree {traceId : The trace id printed by refract:scenario}';

    protected $description = 'Read a trace back from Langfuse and print it as a tree';

    public function handle(TreeReader $reader): int
    {
        $traceId = $this->argument('traceId');

        try {
            $this->line($reader->tree(is_string($traceId) ? $traceId : ''));
        } catch (MissingKeys $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
