<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;

/**
 * Calls BrokenTool, so the tool and then the run fail.
 */
#[Model('fake')]
class BrokenToolAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake one step that calls BrokenTool.
     */
    public static function fakeSteps(): void
    {
        static::fake([
            new ToolCall('call_1', 'BrokenTool', []),
            'Never reached.',
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
