<?php

namespace Workbench\App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * A clock whose handler always throws.
 */
class BrokenClock implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get the current time.';
    }

    public function handle(Request $request): Stringable|string
    {
        throw new RuntimeException('The clock is broken.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
