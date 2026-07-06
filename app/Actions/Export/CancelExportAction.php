<?php

namespace App\Actions\Export;

use App\Models\Export;
use App\Support\Export\ExportState;

/**
 * Requests cancellation of an export. A pending export is cancelled immediately;
 * a processing one gets a volatile flag the worker honors mid-stream. A terminal
 * export cannot be cancelled.
 */
class CancelExportAction
{
    /** @var ExportState */
    private $state;

    public function __construct(ExportState $state)
    {
        $this->state = $state;
    }

    /**
     * @return bool whether the cancellation was accepted
     */
    public function execute(Export $export): bool
    {
        if ($this->isTerminal($export)) {
            return false;
        }

        // Always raise the volatile flag first: a worker that has already picked
        // the job up (or is about to) honors it at its next checkpoint. This
        // closes the pending→processing TOCTOU window.
        $this->state->requestCancel($export->uuid);

        // Cancel atomically only while still pending (not yet claimed by a worker).
        $cancelled = Export::where('id', $export->id)
            ->where('status', Export::STATUS_PENDING)
            ->update(['status' => Export::STATUS_CANCELLED, 'completed_at' => now()]);

        if ($cancelled > 0) {
            $export->setAttribute('status', Export::STATUS_CANCELLED);
        }

        return true;
    }

    private function isTerminal(Export $export): bool
    {
        return in_array(
            $export->status,
            [Export::STATUS_COMPLETED, Export::STATUS_FAILED, Export::STATUS_CANCELLED],
            true
        );
    }
}
