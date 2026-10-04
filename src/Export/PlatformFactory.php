<?php

namespace Anantrp\Refract\Export;

use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Settings;

/**
 * Builds the platform of the configured destination, from the class its config names.
 */
class PlatformFactory
{
    /**
     * The destination used when none is set.
     */
    public const DEFAULT_DESTINATION = 'otlp';

    /**
     * Build the configured destination's platform, or get null when it cannot send.
     *
     * The destination is one of the keys under "destinations" in the config.
     * Its "platform" names a class that implements Platform; any other value
     * exports nothing, with one warning.
     */
    public static function fromConfig(): ?Platform
    {
        $destination = Settings::choice('destination', Settings::keys('destinations'), self::DEFAULT_DESTINATION);
        $key = "destinations.{$destination}";
        $class = Settings::string("{$key}.platform");

        if (! is_a($class, Platform::class, true)) {
            Diagnostics::warn("config.{$key}.platform", "Refract config [refract.{$key}.platform] must name a class that implements ".Platform::class.'. Nothing is exported.');

            return null;
        }

        return $class::fromConfig($key);
    }
}
