<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Anantrp\Refract\Tests\Support\RefractStack;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Ai\Tools\CurrentTime;

/**
 * Counts each call of instructions() and tools(), by the SDK and by Refract.
 */
#[Model('fake')]
class CountingAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * The calls made by the SDK, by method.
     *
     * @var array<string, int>
     */
    public static array $sdkCalls = [];

    /**
     * The calls made by Refract, by method.
     *
     * @var array<string, int>
     */
    public static array $refractCalls = [];

    /**
     * Fake three steps: a CurrentTime call, a TimeAgent sub-agent call, then the answer.
     */
    public static function fakeSteps(): void
    {
        static::fake([
            new ToolCall('call_1', 'CurrentTime', []),
            new ToolCall('call_2', 'TimeAgent', ['task' => 'What time is it?']),
            'It is 12:00.',
        ]);

        TimeAgent::fakeTwoSteps();
    }

    public static function count(string $method): void
    {
        if (RefractStack::calledByRefract()) {
            self::$refractCalls[$method] = (self::$refractCalls[$method] ?? 0) + 1;
        } else {
            self::$sdkCalls[$method] = (self::$sdkCalls[$method] ?? 0) + 1;
        }
    }

    public function instructions(): Stringable|string
    {
        self::count('instructions');

        return 'Tell the time.';
    }

    public function tools(): iterable
    {
        self::count('tools');

        return [new CurrentTime, new TimeAgent];
    }
}
