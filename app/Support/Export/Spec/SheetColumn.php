<?php

namespace App\Support\Export\Spec;

/**
 * One output column of a sheet: either a plain field or an aggregate over a
 * field (fn set). `field` is null for COUNT(*). `label` is the header/output key.
 * Field and fn are public aliases validated against the source whitelist.
 */
final class SheetColumn
{
    /** @var string|null */
    private $field;

    /** @var string|null */
    private $fn;

    /** @var string */
    private $label;

    private function __construct(?string $field, ?string $fn, string $label)
    {
        $this->field = $field;
        $this->fn = $fn;
        $this->label = $label;
    }

    public static function plain(string $field, string $label): self
    {
        return new self($field, null, $label);
    }

    public static function aggregate(?string $field, string $fn, string $label): self
    {
        return new self($field, $fn, $label);
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function fn(): ?string
    {
        return $this->fn;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isAggregate(): bool
    {
        return $this->fn !== null;
    }
}
