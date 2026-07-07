<?php

namespace App\Jobs;

use App\Models\Answer;
use App\Models\AnswerOption;
use App\Models\Import;
use App\Models\Question;
use App\Support\Ingestion\PlayerResolver;
use App\Support\Ingestion\RowFieldNormalizer;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Ingests an answers batch (append, idempotent). Direct alternative to the
 * event-driven `answer_submitted` event type: rows inserted here have
 * `event_id = NULL`. Resolves each row's player (player_id, or player_email as
 * a fallback), deduped on the table's natural unique key
 * (version_id, player_id, question_id) via insertOrIgnore — no `dedup_key`
 * needed here, unlike events/transactions/rewards. Rows with an unresolvable
 * player, or an unknown question_id/answer_option_id, are counted `failed`
 * without blocking the batch.
 */
class IngestAnswersJob extends AbstractIngestJob
{
    /**
     * @return array{processed:int, inserted:int, duplicates:int, failed:int}
     */
    protected function process(Import $import, LoggerInterface $logger): array
    {
        $versionId = (int) $import->version_id;
        $resolver = app(PlayerResolver::class);
        $normalizer = app(RowFieldNormalizer::class);

        return $this->processInChunks($import, function (array $chunk) use ($versionId, $resolver, $normalizer) {
            return $this->ingestChunk($versionId, $chunk, $resolver, $normalizer);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @return array{inserted:int, duplicates:int, failed:int}
     */
    private function ingestChunk(
        int $versionId,
        array $chunk,
        PlayerResolver $resolver,
        RowFieldNormalizer $normalizer
    ): array {
        $now = Carbon::now()->toDateTimeString();
        [$validIds, $playerByEmail] = $resolver->candidatesFor($versionId, $chunk);
        $validQuestionIds = $this->existingIds(Question::class, $versionId, array_column($chunk, 'question_id'));
        $validOptionIds = $this->existingIds(
            AnswerOption::class,
            $versionId,
            array_column($chunk, 'answer_option_id')
        );

        $rows = [];
        $failed = 0;
        foreach ($chunk as $row) {
            $playerId = $resolver->resolveRow($row, $validIds, $playerByEmail);
            $questionId = isset($row['question_id']) ? (int) $row['question_id'] : null;
            $optionId = isset($row['answer_option_id']) ? (int) $row['answer_option_id'] : null;

            if (
                $playerId === null
                || ! isset($validQuestionIds[$questionId])
                || ($optionId !== null && ! isset($validOptionIds[$optionId]))
            ) {
                $failed++;
                continue;
            }

            $rows[] = [
                'version_id' => $versionId,
                'player_id' => $playerId,
                'question_id' => $questionId,
                'answer_option_id' => $optionId,
                'answer_text' => $row['answer_text'] ?? null,
                'occurred_at' => $normalizer->toUtc($row['occurred_at']),
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return ['inserted' => 0, 'duplicates' => 0, 'failed' => $failed];
        }

        $affected = Answer::insertOrIgnore($rows);

        return [
            'inserted' => $affected,
            'duplicates' => count($rows) - $affected,
            'failed' => $failed,
        ];
    }

    /**
     * Subset of the given ids that actually belong to the version, for a
     * `question`/`answer_option`-shaped model. An unknown id is dropped rather
     * than left to fail the insert on the FK constraint (RESTRICT).
     *
     * @param class-string $model
     * @param array<int, mixed> $ids
     * @return array<int, bool> valid id => true
     */
    private function existingIds(string $model, int $versionId, array $ids): array
    {
        $ids = array_filter(array_map(function ($id) {
            return $id !== null ? (int) $id : null;
        }, $ids));

        if ($ids === []) {
            return [];
        }

        $rows = $model::where('version_id', $versionId)
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('id');

        $valid = [];
        foreach ($rows as $id) {
            $valid[(int) $id] = true;
        }

        return $valid;
    }
}
