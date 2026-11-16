<?php

namespace Anantrp\Refract\Transport\Collectors;

/**
 * Turns a run tree into the JSON a collector stores, and back.
 *
 * Each neutral span carries its format version ("v"), so a stored tree
 * says which format it is in. Invalid UTF-8 is replaced and floats keep
 * their fraction (1.0 stays a float), so every tree survives the trip.
 */
class TreePayload
{
    /**
     * The flags a tree is encoded with. A value JSON cannot hold (INF, NAN) becomes 0 instead of failing the tree.
     */
    protected const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * Encode the given run tree, or get null when it cannot be encoded.
     *
     * @param  list<array<string, mixed>>  $tree
     */
    public static function encode(array $tree): ?string
    {
        $json = json_encode($tree, self::FLAGS);

        return $json === false ? null : $json;
    }

    /**
     * Decode the given payload into a run tree, or get null when it is not one.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function decode(string $payload): ?array
    {
        $spans = json_decode($payload, true);

        if (! is_array($spans) || ! array_is_list($spans) || $spans === []) {
            return null;
        }

        $tree = [];

        foreach ($spans as $span) {
            if (! is_array($span)) {
                return null;
            }

            $tree[] = array_filter($span, is_string(...), ARRAY_FILTER_USE_KEY);
        }

        return $tree;
    }
}
