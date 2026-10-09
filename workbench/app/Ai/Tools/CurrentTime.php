<?php

namespace Workbench\App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CurrentTime implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get the current time.';
    }

    public function handle(Request $request): Stringable|string
    {
        return '12:00';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
