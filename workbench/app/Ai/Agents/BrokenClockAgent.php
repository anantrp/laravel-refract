<?php

namespace Workbench\App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;
use Workbench\App\Ai\Tools\BrokenClock;

/**
 * Calls BrokenClock. The SDK hands the failure back to the model, so the run goes on.
 */
#[Model('fake')]
class BrokenClockAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake two steps: one BrokenClock call, then the answer.
     */
    public static function fakeTwoSteps(): void
    {
        static::fake([
            new ToolCall('call_1', 'BrokenClock', []),
            'The clock is broken.',
        ]);
    }

    public function instructions(): Stringable|string
    {
        return 'Tell the time.';
    }

    public function tools(): iterable
    {
        return [new BrokenClock];
    }
}
