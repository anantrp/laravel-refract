<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;

#[Model('fake')]
class FileAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake two steps: one FileTool call, then the answer.
     */
    public static function fakeTwoSteps(): void
    {
        static::fake([
            new ToolCall('call_1', 'FileTool', []),
            'Here is the file.',
        ]);
    }

    public function instructions(): Stringable|string
    {
        return 'Get the file.';
    }

    public function tools(): iterable
    {
        return [new FileTool];
    }
}
