<?php

use Anantrp\Refract\Export\GenAiTranslator;

it('R13: a failed span with no exception class has error.type _OTHER', function () {
    $span = (new GenAiTranslator)->translate([
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'parent_span_id' => null,
        'kind' => 'execute_tool', 'start' => 1, 'end' => 2, 'status' => 'error', 'status_message' => null,
        'call' => ['tool' => 'CurrentTime'], 'content' => [], 'context' => [], 'events' => [],
    ]);

    expect($span['attributes'])->toHaveKey('error.type', '_OTHER')
        ->and($span['status'])->toBe(['code' => GenAiTranslator::STATUS_ERROR, 'message' => null]);
});
