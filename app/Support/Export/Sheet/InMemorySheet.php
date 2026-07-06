<?php

namespace App\Support\Export\Sheet;

/**
 * A materialized sheet backed by in-memory rows. Useful wherever a sheet's rows
 * are already computed (or fixed) rather than streamed from a query.
 */
final class InMemorySheet implements Sheet
{
    /** @var string */
    private $name;

    /** @var array<int, string> */
    private $header;

    /** @var array<int, array<int, scalar|null>> */
    private $rows;

    /**
     * @param array<int, string> $header
     * @param array<int, array<int, scalar|null>> $rows
     */
    public function __construct(string $name, array $header, array $rows)
    {
        $this->name = $name;
        $this->header = $header;
        $this->rows = $rows;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<int, string>
     */
    public function header(): array
    {
        return $this->header;
    }

    /**
     * @return iterable<int, array<int, scalar|null>>
     */
    public function rows(): iterable
    {
        return $this->rows;
    }
}
