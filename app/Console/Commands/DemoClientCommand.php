<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Export;
use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Machine-to-machine client that drives the full API over HTTP: create a version,
 * ingest players and events, request an export, poll its progress and download the
 * XLSX. Doubles as living documentation (prints the equivalent cURL per step).
 */
class DemoClientCommand extends Command
{
    /** @var string */
    protected $signature = 'gamindo:demo-client
        {--base-url=http://localhost:8000 : Base URL of the running API}
        {--players=5 : How many players to create}
        {--events=20 : How many events to send}
        {--version-uuid= : Reuse an existing version uuid instead of creating one}';

    /** @var string */
    protected $description = 'Exercises the whole API end-to-end (players → events → export → download).';

    private const POLL_ATTEMPTS = 60;
    private const POLL_SLEEP_SECONDS = 1;
    private const EVENT_TYPES = [Event::TYPE_GAME_COMPLETED, Event::TYPE_TRANSACTION, Event::TYPE_REWARD_GRANTED];

    public function handle(): int
    {
        $base = rtrim((string) $this->option('base-url'), '/');
        $this->info("Gamindo demo-client → {$base}/api/v1");

        $versionUuid = (string) ($this->option('version-uuid') ?: $this->createVersion());
        if ($versionUuid === '') {
            return self::FAILURE;
        }

        if (! $this->ingestPlayers($versionUuid, (int) $this->option('players'))) {
            return self::FAILURE;
        }

        $playerIds = $this->fetchPlayerIds($versionUuid);
        if ($playerIds === []) {
            $this->error('No players available for this version.');
            return self::FAILURE;
        }

        if (! $this->ingestEvents($versionUuid, $playerIds, (int) $this->option('events'))) {
            return self::FAILURE;
        }

        $exportUuid = $this->createExport($versionUuid);
        if ($exportUuid === null || ! $this->awaitExport($exportUuid)) {
            return self::FAILURE;
        }

        $this->download($exportUuid);
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function createVersion(): ?string
    {
        $payload = ['name' => 'Demo ' . date('Y-m-d H:i:s')];
        $this->step('POST', 'versions', $payload);

        $response = $this->client()->post('versions', $payload);
        if (! $this->ok($response, 201)) {
            return null;
        }

        $uuid = (string) $response->json('data.uuid');
        $this->line("  version = {$uuid}");

        return $uuid;
    }

    private function ingestPlayers(string $versionUuid, int $count): bool
    {
        $players = [];
        for ($i = 1; $i <= max(1, $count); $i++) {
            $players[] = ['email' => "demo+{$i}@example.com", 'language' => 'it', 'total_score' => $i];
        }

        $this->step('POST', "versions/{$versionUuid}/players", ['players' => '…' . count($players) . ' rows']);
        $response = $this->client()->post("versions/{$versionUuid}/players", ['players' => $players]);
        if (! $this->ok($response, 202)) {
            return false;
        }

        return $this->awaitImport((string) $response->json('data.id'));
    }

    /**
     * @return array<int, int>
     */
    private function fetchPlayerIds(string $versionUuid): array
    {
        $this->step('GET', "versions/{$versionUuid}/players", null);
        $response = $this->client()->get("versions/{$versionUuid}/players");
        if (! $this->ok($response, 200)) {
            return [];
        }

        $ids = [];
        foreach ((array) $response->json('data', []) as $player) {
            if (isset($player['id'])) {
                $ids[] = (int) $player['id'];
            }
        }
        $this->line('  players = ' . implode(', ', $ids));

        return $ids;
    }

    /**
     * @param array<int, int> $playerIds
     */
    private function ingestEvents(string $versionUuid, array $playerIds, int $count): bool
    {
        $events = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $events[] = $this->eventRow($playerIds[$i % count($playerIds)], $i);
        }

        $this->step('POST', "versions/{$versionUuid}/events", ['events' => '…' . count($events) . ' rows']);
        $response = $this->client()->post("versions/{$versionUuid}/events", ['events' => $events]);
        if (! $this->ok($response, 202)) {
            return false;
        }

        return $this->awaitImport((string) $response->json('data.id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRow(int $playerId, int $index): array
    {
        $type = self::EVENT_TYPES[$index % count(self::EVENT_TYPES)];
        $row = [
            'player_id' => $playerId,
            'type' => $type,
            'occurred_at' => date('c'),
            'dedup_key' => "demo-evt-{$index}",
            'payload' => ['score' => ($index % 100)],
        ];

        if ($type === Event::TYPE_TRANSACTION) {
            $row['payload'] = ['type' => 'purchase', 'amount' => 9.99, 'currency' => 'EUR'];
        } elseif ($type === Event::TYPE_REWARD_GRANTED) {
            $row['payload'] = ['reward_type' => 'coupon', 'reward_code' => "DEMO{$index}"];
        }

        return $row;
    }

    private function createExport(string $versionUuid): ?string
    {
        $payload = ['sheets' => [['name' => 'events_summary', 'group_by' => ['type'], 'metrics' => ['count']]]];
        $this->step('POST', "versions/{$versionUuid}/exports", $payload);

        $response = $this->client()->post("versions/{$versionUuid}/exports", $payload);
        if (! $this->ok($response, 202)) {
            return null;
        }

        $uuid = (string) $response->json('data.id');
        $this->line("  export = {$uuid}");

        return $uuid;
    }

    private function awaitExport(string $exportUuid): bool
    {
        $this->step('GET', "exports/{$exportUuid}", null);

        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $response = $this->client()->get("exports/{$exportUuid}");
            if (! $this->ok($response, 200)) {
                return false;
            }

            $status = (string) $response->json('data.status');
            $this->line("  status={$status} progress={$response->json('data.progress')}%");

            if ($status === Export::STATUS_COMPLETED) {
                return true;
            }
            if (in_array($status, [Export::STATUS_FAILED, Export::STATUS_CANCELLED], true)) {
                $this->error("Export ended as {$status}.");
                return false;
            }

            $this->sleep();
        }

        $this->error('Export did not complete in time.');

        return false;
    }

    private function download(string $exportUuid): void
    {
        $this->step('GET', "exports/{$exportUuid}/download", null);
        $response = $this->client()->get("exports/{$exportUuid}/download");
        if (! $this->ok($response, 200)) {
            return;
        }

        $path = "exports/demo-{$exportUuid}.xlsx";
        Storage::disk('local')->put($path, $response->body());
        $this->line('  saved → ' . Storage::disk('local')->path($path));
    }

    private function awaitImport(string $importUuid): bool
    {
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $response = $this->client()->get("imports/{$importUuid}");
            if (! $this->ok($response, 200)) {
                return false;
            }

            $status = (string) $response->json('data.status');
            if ($status === Import::STATUS_COMPLETED) {
                $this->line("  import {$importUuid} completed");
                return true;
            }
            if ($status === Import::STATUS_FAILED) {
                $this->error("Import {$importUuid} failed.");
                return false;
            }

            $this->sleep();
        }

        $this->error("Import {$importUuid} did not complete in time.");

        return false;
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) $this->option('base-url'), '/') . '/api/v1')->acceptJson();

        $key = (string) config('gamindo.api_key', '');
        if ($key !== '') {
            $request = $request->withHeaders(['X-Api-Key' => $key]);
        }

        return $request;
    }

    private function ok(Response $response, int $expected): bool
    {
        if ($response->status() === $expected) {
            return true;
        }

        $this->error("  ✗ HTTP {$response->status()} (expected {$expected}): " . $response->body());

        return false;
    }

    /**
     * Prints the step and its equivalent cURL, for copy-paste into a README.
     *
     * @param array<string, mixed>|null $body
     */
    private function step(string $method, string $path, ?array $body): void
    {
        $curl = "curl -X {$method} \"\$BASE/api/v1/{$path}\"";
        if (config('gamindo.api_key')) {
            $curl .= " -H \"X-Api-Key: \$API_KEY\"";
        }
        if ($body !== null) {
            $curl .= " -H 'Content-Type: application/json' -d '" . json_encode($body) . "'";
        }

        $this->newLine();
        $this->comment("→ {$method} /{$path}");
        $this->line("  {$curl}");
    }

    private function sleep(): void
    {
        // Real polling wait; skipped automatically under Http::fake (no live worker).
        if (app()->runningUnitTests()) {
            return;
        }

        sleep(self::POLL_SLEEP_SECONDS);
    }
}
