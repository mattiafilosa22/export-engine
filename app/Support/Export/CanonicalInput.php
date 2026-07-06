<?php

namespace App\Support\Export;

/**
 * Shared readers for the canonical export input format, used by both the boundary
 * validation (StoreExportRequest) and the parser (ExportSpecParser) so the two
 * layers interpret sheet names and field aliases identically — without a separate
 * normalizer/translation step.
 */
final class CanonicalInput
{
    public const DEFAULT_SOURCE = 'events';

    private const PAYLOAD_PREFIX = 'payload.';

    /**
     * Resolves a sheet's source: explicit `source`, else mapped from `name`,
     * else the default source.
     *
     * @param array<string, mixed> $sheet
     */
    public static function resolveSource(array $sheet): string
    {
        if (isset($sheet['source'])) {
            return (string) $sheet['source'];
        }

        $name = isset($sheet['name']) ? (string) $sheet['name'] : null;
        $map = (array) config('gamindo.export.sheet_source_map', []);
        if ($name !== null && isset($map[$name])) {
            return (string) $map[$name];
        }

        return self::DEFAULT_SOURCE;
    }

    /**
     * Strips the "payload." dot-notation prefix from a field alias.
     */
    public static function alias(string $value): string
    {
        return strpos($value, self::PAYLOAD_PREFIX) === 0
            ? substr($value, strlen(self::PAYLOAD_PREFIX))
            : $value;
    }

    /**
     * Normalizes a sort entry — a "column:direction" string or a
     * {column, direction} object — to an aliased column and a lowercased
     * direction. The direction is NOT coerced to asc/desc here, so validation
     * can still reject an invalid one; the parser coerces it afterwards.
     *
     * @param mixed $entry
     * @return array{column: string, direction: string}
     */
    public static function sortEntry($entry): array
    {
        if (is_string($entry)) {
            $parts = explode(':', $entry, 2);
            return ['column' => self::alias($parts[0]), 'direction' => strtolower($parts[1] ?? 'asc')];
        }

        $entry = (array) $entry;
        $column = isset($entry['column']) ? self::alias((string) $entry['column']) : '';

        return ['column' => $column, 'direction' => strtolower((string) ($entry['direction'] ?? 'asc'))];
    }
}
