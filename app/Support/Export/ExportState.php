<?php

namespace App\Support\Export;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Volatile per-export state (live progress %, cancellation flag) kept in the
 * cache — Redis in dev/prod, array in tests. No domain logic: just read/write
 * of ephemeral, self-expiring keys. MySQL stays the durable source of truth.
 */
class ExportState
{
    // Self-expiring TTL (seconds): the state is transient by design.
    private const TTL = 3600;

    // Shared cache-key namespace for everything about an export (progress, cancel, lock).
    private const PREFIX = 'export:';

    /** @var Cache */
    private $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function setProgress(string $uuid, int $percent): void
    {
        $this->cache->put($this->progressKey($uuid), $percent, self::TTL);
    }

    public function progress(string $uuid): ?int
    {
        $value = $this->cache->get($this->progressKey($uuid));

        return $value === null ? null : (int) $value;
    }

    public function requestCancel(string $uuid): void
    {
        $this->cache->put($this->cancelKey($uuid), true, self::TTL);
    }

    public function isCancelRequested(string $uuid): bool
    {
        return (bool) $this->cache->get($this->cancelKey($uuid), false);
    }

    public function forget(string $uuid): void
    {
        $this->cache->forget($this->progressKey($uuid));
        $this->cache->forget($this->cancelKey($uuid));
    }

    /**
     * Distributed-lock key for an export (one export = one worker).
     */
    public function lockKey(string $uuid): string
    {
        return self::PREFIX . $uuid;
    }

    private function progressKey(string $uuid): string
    {
        return self::PREFIX . $uuid . ':progress';
    }

    private function cancelKey(string $uuid): string
    {
        return self::PREFIX . $uuid . ':cancel';
    }
}
