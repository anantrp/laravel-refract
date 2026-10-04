<?php

use Anantrp\Refract\Export\Platforms\Langfuse\LangfusePlatform;

function translatedSpan(string $id, ?string $parent, int $start, int $end): array
{
    return [
        'trace_id' => str_repeat('a', 32),
        'span_id' => $id,
        'parent_span_id' => $parent,
        'name' => $id,
        'kind' => 1,
        'start' => $start,
        'end' => $end,
        'attributes' => [],
        'events' => [],
        'status' => ['code' => 1, 'message' => null],
    ];
}

it('R11: Langfuse moves siblings that start in the same ms to distinct ms, children stay inside their parent', function () {
    $ms = 1_000_000;
    $base = 1_700_000_000_000 * $ms;

    $spans = (new LangfusePlatform('', 'pk', 'sk'))->prepare([
        translatedSpan('run', null, $base, $base + 10 * $ms),
        translatedSpan('chat0', 'run', $base + 100, $base + 2 * $ms),
        translatedSpan('tool', 'run', $base + 3 * $ms + 100, $base + 3 * $ms + 200),
        translatedSpan('chat1', 'run', $base + 3 * $ms + 300, $base + 3 * $ms + 400),
        translatedSpan('sub', 'chat1', $base + 3 * $ms + 350, $base + 3 * $ms + 360),
    ]);

    $byId = array_column($spans, null, 'span_id');
    $msOf = fn (string $id) => intdiv($byId[$id]['start'], $ms);

    expect($msOf('tool'))->toBe(intdiv($base, $ms) + 3)
        ->and($msOf('chat1'))->toBe(intdiv($base, $ms) + 4)
        ->and($byId['sub']['start'])->toBeGreaterThanOrEqual($byId['chat1']['start'])
        ->and($byId['chat1']['end'])->toBeGreaterThanOrEqual($byId['chat1']['start'])
        ->and($byId['sub']['end'])->toBeGreaterThanOrEqual($byId['sub']['start'])
        ->and($byId['run']['end'])->toBeGreaterThanOrEqual($byId['chat1']['end'])
        ->and($byId['chat1']['end'])->toBeGreaterThanOrEqual($byId['sub']['end'])
        ->and(array_column($spans, 'span_id'))->toBe(['run', 'chat0', 'tool', 'chat1', 'sub']);
});
