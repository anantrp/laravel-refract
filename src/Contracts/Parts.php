<?php

namespace Anantrp\Refract\Contracts;

/**
 * An exporter that can split a batch into the parts it sends in one request each.
 *
 * The transport sends each part on its own, so a failed or retried part never sends another part again.
 */
interface Parts
{
    /**
     * Split the given neutral spans into parts, each sent in one request.
     *
     * @param  list<array<string, mixed>>  $spans
     * @return list<list<array<string, mixed>>>
     */
    public function parts(array $spans): array;
}
