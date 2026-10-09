<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;

/**
 * Calls CountingAgent as a sub-agent, so its name() and description() are needed by the SDK.
 */
#[Model('fake')]
class CountingSupervisor implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake two steps: one CountingAgent call, then the answer. CountingAgent runs its own steps.
     */
    public static function fakeSteps(): void
    {
        static::fake([
            new ToolCall('call_s', 'counting_agent', ['task' => 'What time is it?']),
            'It is 12:00.',
        ]);

        CountingAgent::fakeSteps();
    }

    public function instructions(): Stringable|string
    {
        return 'Ask CountingAgent for the time.';
    }

    public function tools(): iterable
    {
        return [new CountingAgent];
    }
}
