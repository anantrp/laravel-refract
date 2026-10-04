<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Ai\Agents\TimeAgent;

class RunAgent implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        TimeAgent::fakeTwoSteps();

        TimeAgent::make()->prompt('What time is it?');
    }
}
