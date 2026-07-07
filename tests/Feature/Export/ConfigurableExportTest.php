<?php

namespace Tests\Feature\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Event;
use App\Models\Export;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class ConfigurableExportTest extends ExportTestCase
{
    public function test_it_reproduces_the_requested_sheets_from_params(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        $p1 = Player::factory()->create(['version_id' => $version->id]);
        $p2 = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->forPlayer($p1)->withPayload('it', 'linkedin', 100)->create(['type' => 'game_completed']);
        Event::factory()->forPlayer($p2)->withPayload('it', 'linkedin', 200)->create(['type' => 'game_completed']);
        Event::factory()->forPlayer($p1)->withPayload('en', 'facebook', 0)->create(['type' => 'answer_submitted']);

        $export = $this->queueExport($version, ['sheets' => [
            [
                'source' => 'events',
                'name' => 'Summary',
                'columns' => [
                    'type', 'language', 'utm_source',
                    ['fn' => 'count_distinct', 'field' => 'player_id', 'as' => 'unique_players'],
                    ['fn' => 'avg', 'field' => 'score', 'as' => 'avg_score'],
                    ['fn' => 'count', 'as' => 'events_count'],
                ],
                'group_by' => ['type', 'language', 'utm_source'],
            ],
            [
                'source' => 'events',
                'name' => 'Detail',
                'columns' => ['id', 'type', 'score'],
                'sort' => [['column' => 'id', 'direction' => 'asc']],
            ],
        ]]);

        $this->work();

        $export->refresh();
        $this->assertSame(Export::STATUS_COMPLETED, $export->status);

        $sheets = $this->readSheets($export);
        $this->assertSame(['Summary', 'Detail'], array_keys($sheets));

        $this->assertSame(
            ['type', 'language', 'utm_source', 'unique_players', 'avg_score', 'events_count'],
            $sheets['Summary'][0]
        );
        $segment = $this->firstDataRow($sheets['Summary'], ['game_completed', 'it', 'linkedin']);
        $this->assertNotNull($segment);
        $this->assertEquals(2, $segment[3]);     // unique_players
        $this->assertEquals(150, $segment[4]);   // avg_score
        $this->assertEquals(2, $segment[5]);     // events_count

        // Detail: 3 events, header + 3 rows.
        $this->assertSame(['id', 'type', 'score'], $sheets['Detail'][0]);
        $this->assertCount(4, $sheets['Detail']);
    }

    public function test_it_returns_202_and_queues_the_job_with_a_valid_spec(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports", [
            'sheets' => [[
                'source' => 'events',
                'columns' => ['type', ['fn' => 'count', 'as' => 'events_count']],
                'group_by' => ['type'],
            ]],
        ]);

        $response->assertStatus(202)->assertJsonPath('data.status', Export::STATUS_PENDING);
        Queue::assertPushed(GenerateExportJob::class);
    }

    /**
     * @dataProvider invalidParams
     * @param array<string, mixed> $params
     */
    public function test_it_rejects_invalid_params_with_422(array $params): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports", $params);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function invalidParams(): array
    {
        return [
            'unknown source' => [['sheets' => [['source' => 'ghosts', 'columns' => ['id']]]]],
            'unknown column' => [['sheets' => [['source' => 'events', 'columns' => ['nope']]]]],
            'illegal aggregate' => [['sheets' => [[
                'source' => 'events',
                'columns' => [['fn' => 'avg', 'field' => 'type']],
            ]]]],
            'unknown metric' => [['sheets' => [[
                'source' => 'events',
                'metrics' => ['made_up_metric'],
                'group_by' => ['type'],
            ]]]],
            'unknown filter' => [['sheets' => [[
                'source' => 'events',
                'columns' => ['id'],
                'filters' => ['nope' => 1],
            ]]]],
            'unknown sort column' => [['sheets' => [[
                'source' => 'events',
                'columns' => ['id'],
                'sort' => [['column' => 'nope']],
            ]]]],
            'ungrouped column with aggregate' => [['sheets' => [[
                'source' => 'events',
                'columns' => ['type', ['fn' => 'count', 'as' => 'n']],
                'group_by' => ['language'],
            ]]]],
            'aggregate without group_by' => [['sheets' => [[
                'source' => 'events',
                'columns' => ['type', ['fn' => 'avg', 'field' => 'score', 'as' => 'a']],
            ]]]],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function queueExport(Version $version, array $params): Export
    {
        config(['queue.default' => 'database']);

        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
            'params' => $params,
        ]);

        GenerateExportJob::dispatch($export->uuid);

        return $export;
    }
}
