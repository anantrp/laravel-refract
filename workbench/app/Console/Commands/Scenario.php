<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Workbench\App\Scenarios;

class Scenario extends Command
{
    protected $signature = 'refract:scenario {row : Matrix row id, for example R1}';

    protected $description = 'Run a matrix row scenario and print its trace id on the last line';

    public function handle(Scenarios $scenarios): int
    {
        try {
            $traceId = $scenarios->run($this->rowArgument());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($traceId);

        return self::SUCCESS;
    }

    protected function rowArgument(): string
    {
        $row = $this->argument('row');

        return is_string($row) ? strtoupper($row) : '';
    }
}
