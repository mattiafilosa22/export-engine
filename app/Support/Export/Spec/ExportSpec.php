<?php

namespace App\Support\Export\Spec;

/**
 * Immutable ordered list of sheet specs for one export request.
 */
final class ExportSpec
{
    /** @var array<int, SheetSpec> */
    private $sheets;

    /**
     * @param array<int, SheetSpec> $sheets
     */
    public function __construct(array $sheets)
    {
        $this->sheets = $sheets;
    }

    /**
     * @return array<int, SheetSpec>
     */
    public function sheets(): array
    {
        return $this->sheets;
    }
}
