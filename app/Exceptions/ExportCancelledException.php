<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Signals a cooperative cancellation of an export mid-stream — not a failure.
 * The job catches it to mark the export cancelled and stop, without a retry.
 */
class ExportCancelledException extends RuntimeException
{
}
