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
     * Get the string keys of the given configuration array, or none when it is not an array.
     *
     * @return list<string>
     */
    public static function keys(string $key): array
    {
        $value = config("refract.{$key}");

        return is_array($value) ? array_values(array_filter(array_keys($value), is_string(...))) : [];
    }

    /**
     * Get the given configuration value as one of the allowed strings.
     *
     * A missing or empty value gives the default with no warning, even when
     * the default is not allowed: the caller decides what that means.
     *
     * @param  list<string>  $allowed
     */
    public static function choice(string $key, array $allowed, string $default): string
    {
        $value = strtolower(self::string($key));

        if ($value === '') {
            return $default;
        }

        if (! in_array($value, $allowed, true)) {
            Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must be one of: ".implode(', ', $allowed).'. Using the default.');

            return $default;
        }

        return $value;
    }

    /**
     * Get the given configuration value as an http or https URL with a host.
     */
    public static function url(string $key, string $default = ''): string
    {
        $value = self::string($key, $default);

        if ($value === $default || self::isUrl($value)) {
            return $value;
        }

        Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must be an http or https URL. Using the default.");

        return $default;
    }

    /**
     * Get the given configuration value as the URL of a destination that is
     * sent credentials: an empty string when it is not set, or null and one
     * warning when it is invalid. An invalid URL has no fallback host, so
     * keys and headers never go to a host the user did not name.
     */
    public static function destinationUrl(string $key): ?string
    {
        // Not self::string(): it turns an invalid value (a boolean from env "true") into the empty default.
        $value = config("refract.{$key}");

        if ($value === null || $value === '') {
            return '';
        }

        if (is_string($value) && self::isUrl($value)) {
            return $value;
        }

        Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must be an http or https URL. Nothing is exported.");

        return null;
    }

    /**
     * Determine if the given value is an http or https URL with a host.
     */
    protected static function isUrl(string $value): bool
    {
        // Not FILTER_VALIDATE_URL: it rejects valid hosts like "otel_collector" (docker-compose names) and IDN hosts.
        $parts = parse_url($value);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        return in_array(strtolower($scheme), ['http', 'https'], true) && $host !== '';
    }

    /**
     * Get the given configuration value as HTTP headers in the OpenTelemetry
     * format: "name1=value1,name2=value2", with URL-encoded values.
     *
     * Names are lowercased. Any entry that is not a valid header makes the
     * whole value invalid.
     *
     * @see https://opentelemetry.io/docs/specs/otel/protocol/exporter/#specifying-headers-via-environment-variables
     *
     * @return array<string, string>
     */
    public static function headers(string $key): array
    {
        $value = self::string($key);
        $headers = [];

        foreach (explode(',', $value) as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            $pair = explode('=', $entry, 2);
            $name = strtolower(trim($pair[0]));
            $header = isset($pair[1]) ? trim(rawurldecode($pair[1])) : null;

            if ($header === null || preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $header) === 1) {
                Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must be headers like \"name1=value1,name2=value2\". Using the default.");

                return [];
            }

            $headers[$name] = $header;
        }

        return $headers;
    }

    /**
     * Get the given configuration value as a whole number of at least the given minimum (above zero).
     */
    public static function positiveInt(string $key, int $default, int $min = 1): int
    {
        $value = config("refract.{$key}");

        if ($value === null || $value === '') {
            return $default;
        }

        $number = is_int($value) || (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => max(1, $min)]])
            : false;

        if (! is_int($number)) {
            Diagnostics::warn("config.{$key}", "Refract config [refract.{$key}] must be a whole number of at least ".max(1, $min).'. Using the default.');

            return $default;
        }

        return $number;
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
