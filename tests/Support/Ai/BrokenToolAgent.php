<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;

/**
 * Calls BrokenTool. The SDK hands the failure back to the model, so the run goes on.
 */
#[Model('fake')]
class BrokenToolAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake two steps: one BrokenTool call, then the answer.
     */
    public static function fakeSteps(): void
    {
        static::fake([
            new ToolCall('call_1', 'BrokenTool', []),
            'The tool failed.',
        ]);
    }

    public function instructions(): Stringable|string
    {
        return 'Use the tool.';
    }

    public function tools(): iterable
    {
        return [new BrokenTool];
    }
}
