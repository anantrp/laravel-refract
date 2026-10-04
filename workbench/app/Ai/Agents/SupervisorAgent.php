<?php

namespace Workbench\App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;

#[Model('fake')]
class SupervisorAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake two steps: one TimeAgent call, then the answer. TimeAgent runs its own two steps.
     */
    public static function fakeTwoSteps(): void
    {
        static::fake([
            new ToolCall('call_1', 'TimeAgent', ['task' => 'What time is it?']),
            'TimeAgent says it is 12:00.',
        ]);

        TimeAgent::fakeTwoSteps();
    }

    public function instructions(): Stringable|string
    {
        return 'Ask TimeAgent for the time.';
    }

    public function tools(): iterable
    {
        return [new TimeAgent];
    }
}
