<?php

namespace Anantrp\Refract\Tests\Support;

/**
 * Sets process environment variables the way a .env file or the server does, and puts them back.
 */
class Env
{
    /**
     * The value each changed variable had before, null when it was not set.
     *
     * @var array<string, array{server: mixed, env: mixed, putenv: string|false}>
     */
    protected static array $original = [];

    /**
     * Set the given variables. A null value removes the variable.
     *
     * @param  array<string, string|null>  $variables
     */
    public static function set(array $variables): void
    {
        foreach ($variables as $name => $value) {
            self::$original[$name] ??= [
                'server' => $_SERVER[$name] ?? null,
                'env' => $_ENV[$name] ?? null,
                'putenv' => getenv($name),
            ];

            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }

    /**
     * Put every changed variable back.
     */
    public static function restore(): void
    {
        foreach (self::$original as $name => $original) {
            unset($_SERVER[$name], $_ENV[$name]);

            if ($original['server'] !== null) {
                $_SERVER[$name] = $original['server'];
            }

            if ($original['env'] !== null) {
                $_ENV[$name] = $original['env'];
            }

            putenv($original['putenv'] === false ? $name : "{$name}={$original['putenv']}");
        }

        self::$original = [];
    }
}
