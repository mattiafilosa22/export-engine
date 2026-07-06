<?php

namespace App\Support\Ingestion;

/**
 * Version-scoped sets of valid foreign ids referenced by typed records (e.g.
 * questions, answer options). Lets the mapper skip a record whose payload points
 * at a row not in the version instead of failing the batch on a FK violation.
 */
class ReferenceSet
{
    /** @var array<string, array<int, bool>> ref name => (valid id => true) */
    private $sets;

    /**
     * @param array<string, array<int, bool>> $sets
     */
    public function __construct(array $sets)
    {
        $this->sets = $sets;
    }

    public function has(string $ref, int $id): bool
    {
        return isset($this->sets[$ref][$id]);
    }
}
