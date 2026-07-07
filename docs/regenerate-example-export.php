<?php

// Regenerates docs/example-export.xlsx: seeds a small realistic dataset through
// the REAL ingestion pipeline (CreateImportAction — the same Action the HTTP
// endpoints use), so Data_Quality is genuinely populated, then requests the same
// multi-sheet export shown in the README and writes the result over this file.
//
// Self-contained: drains the queue itself (no dependency on the external `worker`
// container, so a stale worker process never causes stale-code surprises), and
// talks to the app's own HTTP server from inside the container — no host PHP
// needed. Safe to re-run: creates a fresh version each time.
//
// Usage:
//   docker compose exec app php docs/regenerate-example-export.php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Actions\Ingestion\CreateImportAction;
use App\Models\AnswerOption;
use App\Models\Import;
use App\Models\Question;
use App\Models\Version;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const PLAYER_COUNT = 150;
const LANGUAGES = ['it', 'it', 'it', 'en', 'es'];
const EVENT_TYPES = ['opened', 'game_completed', 'answer_submitted', 'transaction', 'reward_granted'];

function drainQueue(): void
{
    Artisan::call('queue:work', ['--stop-when-empty' => true]);
}

$version = Version::create([
    'name' => 'README Example',
    'client_name' => 'Gamindo',
    'status' => Version::STATUS_ACTIVE,
]);

// Minimal campaign setup (question/answer option), mirroring real campaign config —
// needed so an answer_submitted event resolves to a real `answers` row.
$question = Question::create([
    'version_id' => $version->id,
    'code' => 'Q1',
    'text' => 'Favorite feature?',
    'type' => Question::TYPE_SINGLE_CHOICE,
    'position' => 1,
]);
$option = AnswerOption::create([
    'version_id' => $version->id,
    'question_id' => $question->id,
    'code' => 'A',
    'label' => 'Export engine',
    'position' => 1,
]);

// Real ingestion (through CreateImportAction, same as POST /players): produces a
// genuine, trackable Import row, so the Data_Quality sheet has real content.
$players = [];
for ($i = 1; $i <= PLAYER_COUNT; $i++) {
    $players[] = [
        'email' => "readme{$i}@example.com",
        'language' => LANGUAGES[$i % count(LANGUAGES)],
        'registered_at' => now()->subDays(30 - ($i % 30))->toIso8601String(),
    ];
}
$action = app(CreateImportAction::class);
$action->execute($version, Import::TYPE_PLAYERS, $players);
drainQueue();

$playerIds = DB::table('players')->where('version_id', $version->id)->pluck('id')->all();

// Real ingestion: events (mixed types, incl. typed ones with real question/option refs).
$events = [];
foreach ($playerIds as $i => $playerId) {
    $type = EVENT_TYPES[$i % count(EVENT_TYPES)];
    $payload = ['language' => LANGUAGES[$i % count(LANGUAGES)]];
    if ($type === 'game_completed') {
        $payload['score'] = ($i * 17) % 1000;
    } elseif ($type === 'answer_submitted') {
        $payload = ['question_id' => $question->id, 'answer_option_id' => $option->id];
    } elseif ($type === 'transaction') {
        $payload = ['type' => 'purchase', 'amount' => 9.99 + ($i % 5), 'currency' => 'EUR'];
    } elseif ($type === 'reward_granted') {
        $payload = ['reward_type' => 'coupon', 'reward_code' => "R{$i}"];
    }

    $events[] = [
        'player_id' => $playerId,
        'type' => $type,
        'occurred_at' => now()->subDays(30 - ($i % 30))->toIso8601String(),
        'dedup_key' => "readme-evt-{$i}",
        'payload' => $payload,
    ];
}
$action->execute($version, Import::TYPE_EVENTS, $events);
drainQueue();

echo "Seeded version {$version->uuid}: " . count($players) . " players, " . count($events) . " events\n";

// Same multi-sheet spec shown in the README ("Esempi cURL" / export configurabile),
// plus the opt-in summary sheets.
$spec = [
    'date_from' => now()->subDays(30)->toDateString(),
    'date_to' => now()->toDateString(),
    'include_summary' => true,
    'sheets' => [
        [
            'name' => 'Players', 'source' => 'players',
            'columns' => ['player_id', 'email', 'registered_at', 'total_score'],
            'sort' => ['total_score:desc'],
        ],
        [
            'name' => 'Events_Summary', 'source' => 'events',
            'group_by' => ['type', 'payload.language'],
            'metrics' => ['count', 'unique_players', 'avg_score'],
        ],
        [
            'name' => 'Events_Detail', 'source' => 'events',
            'columns' => ['id', 'type', 'occurred_at', 'language', 'score'],
            'filters' => ['language' => 'it'],
            'sort' => [['column' => 'occurred_at', 'direction' => 'desc']],
        ],
        ['name' => 'Answers', 'source' => 'answers'],
        ['name' => 'Transactions', 'source' => 'transactions', 'sort' => ['occurred_at:desc']],
    ],
];

// In-process HTTP call to the app's own server (this container, unmapped port).
$http = Http::acceptJson();
$apiKey = (string) config('gamindo.api_key', '');
if ($apiKey !== '') {
    $http = $http->withHeaders(['X-Api-Key' => $apiKey]);
}
$base = 'http://localhost:8000/api/v1';

$response = $http->post("{$base}/versions/{$version->uuid}/exports", $spec);
$exportId = $response->json('data.id');
if ($exportId === null) {
    fwrite(STDERR, "Export request failed: " . $response->body() . "\n");
    exit(1);
}
echo "Export requested: {$exportId}\n";

// Drain the export job ourselves too — self-contained, no dependency on the
// external `worker` container being up or running fresh code.
drainQueue();

$export = $http->get("{$base}/exports/{$exportId}")->json('data');
if ($export['status'] !== 'completed') {
    fwrite(STDERR, "Export did not complete (status={$export['status']}): " . json_encode($export) . "\n");
    exit(1);
}
echo "Export completed: {$export['total_rows']} rows, {$export['file_size']} bytes\n";

$file = $http->get("{$base}/exports/{$exportId}/download")->body();
file_put_contents(__DIR__ . '/example-export.xlsx', $file);

echo "Written to docs/example-export.xlsx\n";
