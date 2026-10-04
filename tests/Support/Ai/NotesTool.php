<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Returns a Collection, which the SDK sends to the model as its JSON text.
 */
class NotesTool implements Tool
{
    /**
     * The note text each topic gets.
     */
    public static string $note = 'Ship on Friday.';

    public function description(): Stringable|string
    {
        return 'Read the notes on a topic.';
    }

    /**
     * @return Collection<string, string>
     */
    public function handle(Request $request): Stringable|string
    {
        return collect(['topic' => (string) $request['topic'], 'note' => self::$note]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['topic' => $schema->string()->required()];
    }
}
