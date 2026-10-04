<?php

namespace Workbench\App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[Model('fake')]
class SupervisorAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Ask TimeAgent for the time.';
    }

    public function tools(): iterable
    {
        return [new TimeAgent];
    }
}
