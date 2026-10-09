<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;

#[Model('fake')]
class NotesAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake two steps: one NotesTool call with the given topic, then the given answer.
     */
    public static function fakeTwoSteps(string $topic = 'release', string $answer = 'The release is on Friday.'): void
    {
        static::fake([
            new ToolCall('call_1', 'NotesTool', ['topic' => $topic]),
            $answer,
        ]);
    }

    public function instructions(): Stringable|string
    {
        return 'Read the notes.';
    }

    public function tools(): iterable
    {
        return [new NotesTool];
    }
}
