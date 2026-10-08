<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Workbench\App\Scenarios;

class Scenario extends Command
{
    protected $signature = 'refract:scenario {scenario : Scenario name, for example R1}';

    protected $description = 'Run a scenario and print its trace id on the last line';

    public function handle(Scenarios $scenarios): int
    {
        try {
            $traceId = $scenarios->run($this->scenarioArgument());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($traceId);

        return self::SUCCESS;
    }

    protected function scenarioArgument(): string
    {
        $name = $this->argument('scenario');

        return is_string($name) ? strtoupper($name) : '';
    }
}
