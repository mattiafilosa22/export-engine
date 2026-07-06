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
}
