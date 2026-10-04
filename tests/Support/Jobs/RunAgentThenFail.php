<?php

namespace Anantrp\Refract\Tests\Support\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Workbench\App\Ai\Agents\TimeAgent;

/**
 * Runs an agent, then fails.
 */
class RunAgentThenFail implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        TimeAgent::fakeTwoSteps();

        TimeAgent::make()->prompt('What time is it?');

        throw new RuntimeException('The job failed after the agent ran.');
    }
}
