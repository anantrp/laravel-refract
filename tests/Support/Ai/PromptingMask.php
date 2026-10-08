<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Workbench\App\Ai\Agents\TimeAgent;

/**
 * Prompts TimeAgent the first time it is called, as a mask that asks a model might.
 */
class PromptingMask
{
    /**
     * Whether the mask has prompted its agent.
     */
    public static bool $prompted = false;

    public function __invoke(string $value): string
    {
        if (! self::$prompted) {
            self::$prompted = true;

            TimeAgent::make()->prompt('Help me mask this.');
        }

        return $value;
    }
}
