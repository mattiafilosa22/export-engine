<?php

namespace Tests\Unit\Export;

use App\Support\Export\ExportState;
use Tests\TestCase;

class ExportStateTest extends TestCase
{
    public function test_it_stores_and_reads_progress(): void
    {
        $state = $this->state();

        $this->assertNull($state->progress('u1'));
        $state->setProgress('u1', 42);
        $this->assertSame(42, $state->progress('u1'));
    }

    public function test_it_flags_and_reads_cancellation(): void
    {
        $state = $this->state();

        $this->assertFalse($state->isCancelRequested('u2'));
        $state->requestCancel('u2');
        $this->assertTrue($state->isCancelRequested('u2'));
    }

    public function test_forget_clears_both_keys(): void
    {
        $state = $this->state();
        $state->setProgress('u3', 50);
        $state->requestCancel('u3');

        $state->forget('u3');

        $this->assertNull($state->progress('u3'));
        $this->assertFalse($state->isCancelRequested('u3'));
    }

    private function state(): ExportState
    {
        return $this->app->make(ExportState::class);
    }
}
