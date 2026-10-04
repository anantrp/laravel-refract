<?php

namespace Anantrp\Refract\Support;

use Throwable;

/**
 * Runs Refract's own work so that a failure in it never reaches the app.
 *
 * Only Refract's code runs inside a guard: an exception from the app's
 * own code (an agent, a tool) never passes through one, so it reaches
 * the app unchanged.
 */
class Guard
{
    /**
     * Run the given callback. When it throws, warn once for the key and get the default.
     *
     * The warning names the exception class only: its message may hold captured values.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  TReturn  $default
     * @return TReturn
     */
    public static function run(string $key, string $what, callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Diagnostics::warn($key, "Refract failed {$what} (".$e::class.'). The app was not affected.');

            return $default;
        }
    }
}
