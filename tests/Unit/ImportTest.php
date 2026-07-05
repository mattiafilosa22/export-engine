<?php

namespace Tests\Unit;

use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_processing_sets_status_started_at_and_increments_attempts(): void
    {
        $import = Import::factory()->create(['status' => Import::STATUS_PENDING]);

        $import->markProcessing();

        $this->assertSame(Import::STATUS_PROCESSING, $import->status);
        $this->assertNotNull($import->started_at);
        $this->assertSame(1, $import->attempts);
    }

    public function test_mark_completed_sets_counters_and_completed_at(): void
    {
        $import = Import::factory()->processing()->create();

        $import->markCompleted(100, 98, 1, 1);

        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(100, $import->processed_rows);
        $this->assertSame(98, $import->inserted);
        $this->assertSame(1, $import->duplicates);
        $this->assertSame(1, $import->failed);
        $this->assertNotNull($import->completed_at);
    }

    public function test_mark_failed_sets_status_and_error_message(): void
    {
        $import = Import::factory()->processing()->create();

        $import->markFailed('boom');

        $this->assertSame(Import::STATUS_FAILED, $import->status);
        $this->assertSame('boom', $import->error_message);
    }

    public function test_is_terminal_is_true_only_for_completed_so_failed_imports_can_be_retried(): void
    {
        $this->assertFalse(Import::factory()->create(['status' => Import::STATUS_PENDING])->isTerminal());
        $this->assertFalse(Import::factory()->processing()->create()->isTerminal());
        $this->assertTrue(Import::factory()->completed()->create()->isTerminal());
        // FAILED is not terminal: a retry must be able to reprocess it.
        $this->assertFalse(Import::factory()->create(['status' => Import::STATUS_FAILED])->isTerminal());
    }
}
