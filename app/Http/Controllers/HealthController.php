<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Liveness probe: infrastructure, not a domain use case.
 * Hence it does not use the FormRequest/Action/Resource stack (reserved for domain).
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
