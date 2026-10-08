<?php

namespace Anantrp\Refract\Tests\Support;

use Anantrp\Refract\Capture\Recorder;
use Closure;
use LogicException;

/**
 * A recorder whose every recording method throws, as a bug in Refract might.
 */
class ThrowingRecorder extends Recorder
{
    public function start(string $key, string $kind, ?string $parentKey, array $call, array $context = [], ?string $contextFrom = null, array|Closure $content = []): void
    {
        throw new LogicException('Refract bug in start, SECRET');
    }

    public function end(string $key, string $status = 'ok', ?string $message = null, array $call = [], array $context = [], array|Closure $content = []): void
    {
        throw new LogicException('Refract bug in end, SECRET');
    }

    public function event(string $key, string $kind, array $call = []): void
    {
        throw new LogicException('Refract bug in event, SECRET');
    }

    public function update(string $key, array $call): void
    {
        throw new LogicException('Refract bug in update, SECRET');
    }
}
