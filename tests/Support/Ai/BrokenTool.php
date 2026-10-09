<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;
use Stringable;

/**
 * A tool whose handler always throws.
 */
class BrokenTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Always fails.';
    }

    public function handle(Request $request): Stringable|string
    {
        throw new LogicException('secret detail from the tool');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
