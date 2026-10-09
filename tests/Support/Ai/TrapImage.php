<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Laravel\Ai\Files\Base64Image;

/**
 * A base64 image that keeps every call Refract makes to a method that reads, names or describes it.
 *
 * The SDK itself turns a tool result into text, so only calls with Refract code on the stack count.
 */
class TrapImage extends Base64Image
{
    /**
     * The methods Refract called so far, on any trap file.
     *
     * @var list<string>
     */
    public static array $calls = [];

    /**
     * Keep the given method when Refract code called it.
     */
    public static function called(string $method): void
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';

            if (str_starts_with($class, 'Anantrp\\Refract\\') && ! str_starts_with($class, 'Anantrp\\Refract\\Tests\\')) {
                self::$calls[] = $method;

                return;
            }
        }
    }

    public function content(): string
    {
        self::called('content');

        return 'TRAP-BYTES';
    }

    public function mimeType(): ?string
    {
        self::called('mimeType');

        return 'image/trap';
    }

    public function name(): ?string
    {
        self::called('name');

        return 'TRAP-NAME';
    }

    public function toArray(): array
    {
        self::called('toArray');

        return ['type' => 'trap', 'base64' => 'TRAP-BYTES'];
    }

    public function jsonSerialize(): mixed
    {
        self::called('jsonSerialize');

        return $this->toArray();
    }

    public function __toString(): string
    {
        self::called('__toString');

        return 'TRAP-BYTES';
    }
}
