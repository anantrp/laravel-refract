<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Returns the value a test gives it: a file, or a Collection holding files.
 */
class FileTool implements Tool
{
    /**
     * The value the tool returns.
     */
    public static Stringable|string $result = '';

    public function description(): Stringable|string
    {
        return 'Get the file.';
    }

    public function handle(Request $request): Stringable|string
    {
        return self::$result;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
