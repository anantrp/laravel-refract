<?php

namespace Anantrp\Refract\Support;

/**
 * The one reader of Refract's configuration values.
 *
 * A missing or empty value gives the default. An invalid value gives the
 * default and one warning.
 */
class Settings
{
    /**
     * Get the given configuration value as a string.
     */
    public static function string(string $key, string $default = ''): string
    {
        $value = config("refract.{$key}");

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] is invalid. Using the default.");

            return $default;
        }

        return (string) $value;
    }

    /**
     * Get the given configuration value as a map of non-empty strings to non-empty strings.
     *
     * An entry that is not such a pair is left out, with one warning.
     *
     * @return array<string, string>
     */
    public static function map(string $key): array
    {
        $value = config("refract.{$key}");

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (! is_array($value)) {
            Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must be an array. Using the default.");

            return [];
        }

        $map = [];

        foreach ($value as $from => $to) {
            if (! is_string($from) || $from === '' || ! is_string($to) || $to === '') {
                Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must map names to names. Invalid entries are skipped.");

                continue;
            }

            $map[$from] = $to;
        }

        return $map;
    }

    /**
     * Get the given configuration value as one of the allowed strings.
     *
     * @param  list<string>  $allowed
     */
    public static function choice(string $key, array $allowed, string $default): string
    {
        $value = strtolower(self::string($key, $default));

        if (! in_array($value, $allowed, true)) {
            Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must be one of: ".implode(', ', $allowed).'. Using the default.');

            return $default;
        }

        return $value;
    }

    /**
     * Get the given configuration value as a boolean.
     */
    public static function bool(string $key, bool $default): bool
    {
        $value = config("refract.{$key}");

        if ($value === null || $value === '') {
            return $default;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($bool === null) {
            Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must be true or false. Using the default.");

            return $default;
        }

        return $bool;
    }
}
