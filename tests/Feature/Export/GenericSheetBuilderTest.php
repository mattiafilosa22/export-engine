<?php

namespace Tests\Feature\Export;

use App\Models\Event;
use App\Models\Player;
use App\Models\Version;
use App\Support\Export\Query\FilterApplier;
use App\Support\Export\Sheet\GenericSheetBuilder;
use App\Support\Export\Spec\SheetColumn;
use App\Support\Export\Spec\SheetSpec;

class GenericSheetBuilderTest extends ExportTestCase
{
    public function test_it_reproduces_an_events_summary_from_config(): void
    {
        $version = Version::factory()->create();
        $p1 = Player::factory()->create(['version_id' => $version->id]);
        $p2 = Player::factory()->create(['version_id' => $version->id]);

        // Segment (game_completed, it, linkedin): 2 distinct players, scores 100 & 200.
        Event::factory()->forPlayer($p1)->withPayload('it', 'linkedin', 100)->create(['type' => 'game_completed']);
        Event::factory()->forPlayer($p2)->withPayload('it', 'linkedin', 200)->create(['type' => 'game_completed']);
        // A different segment, must not leak into the one above.
        Event::factory()->forPlayer($p1)->withPayload('en', 'facebook', 0)->create(['type' => 'answer_submitted']);

        $spec = new SheetSpec('Summary', 'events', [
            SheetColumn::plain('type', 'type'),
            SheetColumn::plain('language', 'language'),
            SheetColumn::plain('utm_source', 'utm_source'),
            SheetColumn::aggregate('player_id', 'count_distinct', 'unique_players'),
            SheetColumn::aggregate('score', 'avg', 'avg_score'),
            SheetColumn::aggregate(null, 'count', 'events_count'),
        ], [], ['type', 'language', 'utm_source']);

        $rows = $this->rows($spec, (int) $version->id);

        $segment = $this->matchRow($rows, ['game_completed', 'it', 'linkedin']);
        $this->assertNotNull($segment, 'The it/linkedin/game_completed segment must be present.');
        $this->assertSame(2, $segment[3]);        // unique_players
        $this->assertSame(150.0, $segment[4]);    // avg_score
        $this->assertSame(2, $segment[5]);        // events_count
    }

    public function test_it_filters_and_selects_dynamic_columns_on_a_detail_sheet(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->forPlayer($player)->withPayload('it', 'linkedin', 100)->create(['type' => 'game_completed']);
        Event::factory()->forPlayer($player)->withPayload('en', 'facebook', 0)->create(['type' => 'answer_submitted']);

        // Detail sheet: only id/type/score, filtered to it-language events.
        $spec = new SheetSpec('Detail', 'events', [
            SheetColumn::plain('id', 'id'),
            SheetColumn::plain('type', 'type'),
            SheetColumn::plain('score', 'score'),
        ], ['language' => ['it']]);

        $rows = $this->rows($spec, (int) $version->id);

        $this->assertCount(1, $rows);
        $this->assertSame('game_completed', $rows[0][1]);
        $this->assertSame(100, $rows[0][2]);
    }

    /**
     * @return array<int, array<int, scalar|null>>
     */
    private function rows(SheetSpec $spec, int $versionId): array
    {
        $builder = new GenericSheetBuilder($spec, $versionId, new FilterApplier());

        return iterator_to_array($builder->rows(), false);
    }
}
