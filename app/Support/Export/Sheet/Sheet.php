<?php

namespace App\Support\Export\Sheet;

/**
 * A writable sheet: a name, a header row, and a stream of data rows. The writer
 * depends on this contract, not on concrete builders (DIP).
 */
interface Sheet
{
    public function name(): string;

    /**
     * @return array<int, string>
     */
    public function header(): array;

    /**
     * @return iterable<int, array<int, scalar|null>>
     */
    public function rows(): iterable;

    /**
     * Number of data rows the sheet will produce (used for the progress total).
     */
    public function count(): int;
}
