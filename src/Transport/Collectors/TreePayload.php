<?php

namespace Anantrp\Refract\Transport\Collectors;

/**
 * Turns a run tree into the text a collector stores, and back.
 *
 * A tree is stored as JSON, gzipped and base64 encoded behind the prefix
 * "gz1:", so it takes several times less room and is still plain ASCII
 * for a database text column. A payload without the prefix is plain JSON,
 * as 0.3 stored it, and still decodes.
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
     * The prefix of a gzipped payload.
     */
    public const GZIP = 'gz1:';

    /**
     * The gzip level: most of the size gain, at a fraction of the time of the highest.
     */
    protected const LEVEL = 6;

    /**
     * Encode the given run tree, or get null when it cannot be encoded.
     *
     * @param  list<array<string, mixed>>  $tree
     */
    public static function encode(array $tree): ?string
    {
        $json = json_encode($tree, self::FLAGS);

        if ($json === false) {
            return null;
        }

        $gzipped = gzencode($json, self::LEVEL);

        return $gzipped === false ? null : self::GZIP.base64_encode($gzipped);
    }

    /**
     * Decode the given payload into a run tree, or get null when it is not one.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function decode(string $payload): ?array
    {
        $json = self::json($payload);

        return $json === null ? null : self::tree($json);
    }

    /**
     * Decode the given claimed payloads in order, until the JSON of the trees reaches the given bytes.
     *
     * A claim is sized by the bytes stored, and a tree that compresses
     * well stores in far less than it takes once decoded: the trees past
     * the bytes are not decoded, and their ids come back, so the
     * collector gives them back at once. The first tree is always taken.
     *
     * @param  list<array{array-key, string}>  $payloads  Each claimed id and its payload.
     * @return array{array<array-key, list<array<string, mixed>>>, list<array-key>, list<array-key>} The trees by id, the ids that could not be decoded, and the ids past the bytes.
     */
    public static function decodeClaim(array $payloads, int $maxBytes): array
    {
        $trees = [];
        $broken = [];
        $over = [];
        $used = 0;

        foreach ($payloads as [$id, $payload]) {
            if ($trees !== [] && $used >= $maxBytes) {
                $over[] = $id;

                continue;
            }

            $json = self::json($payload);
            $tree = $json === null ? null : self::tree($json);

            if ($tree === null) {
                $broken[] = $id;

                continue;
            }

            $trees[$id] = $tree;
            $used += strlen((string) $json);
        }

        return [$trees, $broken, $over];
    }

    /**
     * Get the JSON of the given payload, gunzipped when it is gzipped, or null when it cannot be.
     */
    protected static function json(string $payload): ?string
    {
        if (! str_starts_with($payload, self::GZIP)) {
            return $payload;
        }

        $gzipped = base64_decode(substr($payload, strlen(self::GZIP)), true);
        $json = $gzipped === false || $gzipped === '' ? false : @gzdecode($gzipped);

        return $json === false ? null : $json;
    }

    /**
     * Get the run tree in the given JSON, or null when it is not one.
     *
     * @return list<array<string, mixed>>|null
     */
    protected static function tree(string $json): ?array
    {
        $spans = json_decode($json, true);

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
