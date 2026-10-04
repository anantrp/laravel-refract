<?php

namespace Workbench\App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;
use Workbench\App\Ai\Tools\CurrentTime;

#[Model('fake')]
class TimeAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake two steps: one CurrentTime call, then the answer.
     */
    public static function fakeTwoSteps(): void
    {
        static::fake([
            new ToolCall('call_1', 'CurrentTime', []),
            'It is 12:00.',
        ]);
    }

    public function instructions(): Stringable|string
    {
        return 'Tell the time.';
    }

    public function tools(): iterable
    {
        return [new CurrentTime];
    }
}
