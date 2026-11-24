<?php

use Anantrp\Refract\Transport\Collectors\TreePayload;

/*
 * A stored tree is gzipped behind a "gz1:" text prefix, and a plain JSON
 * tree stored by 0.3 still reads.
 */

/**
 * A run tree of one span with the given output.
 *
 * @return list<array<string, mixed>>
 */
function payloadTree(string $output = 'It is 12:00.'): array
{
    return [[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => ['agent' => 'TimeAgent', 'temperature' => 1.0, 'top_p' => 0.5], 'content' => ['output' => $output], 'context' => [], 'events' => [],
    ]];
}

it('round-trips a tree, including floats and invalid UTF-8', function () {
    $payload = TreePayload::encode(payloadTree("bad \xB1\x31 byte, ünïcødé / slash"));

    $span = TreePayload::decode((string) $payload)[0];

    expect($payload)->toStartWith('gz1:')
        ->and($span['call']['temperature'])->toBe(1.0)
        ->and($span['call']['top_p'])->toBe(0.5)
        ->and($span['content']['output'])->toBe("bad \u{FFFD}1 byte, ünïcødé / slash");
});

it('a plain tree stored by 0.3 still decodes', function () {
    $plain = json_encode(payloadTree(), JSON_PRESERVE_ZERO_FRACTION);

    expect(TreePayload::decode((string) $plain))->toBe(payloadTree());
});

it('a compressed payload is plain ASCII', function () {
    $payload = TreePayload::encode(payloadTree(random_bytes(10_000)));

    expect(preg_match('/^[\x20-\x7e]+$/', (string) $payload))->toBe(1);
});

it('a 1.5 MB text tree stores in under a third of its JSON size', function () {
    $sentences = 'The release ships on Friday. Ask the team about the database migration first. '
        .'Customers in Europe see the new pricing a week later. The model answered with three tool calls. ';
    $tree = payloadTree(substr(str_repeat($sentences, 8_000), 0, 1_500_000));

    expect(strlen((string) TreePayload::encode($tree)))->toBeLessThan(intdiv(strlen((string) json_encode($tree)), 3));
});

it('a corrupt gz1 payload decodes to null', function (string $payload) {
    expect(TreePayload::decode($payload))->toBeNull();
})->with([
    'not base64' => ['gz1:***'],
    'not gzip' => ['gz1:'.base64_encode('plain text')],
    'gzip, not a tree' => ['gz1:'.base64_encode((string) gzencode('"text"'))],
    'empty' => ['gz1:'],
]);
