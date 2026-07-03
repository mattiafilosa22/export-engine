<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Liveness probe: infrastruttura, non un caso d'uso di dominio.
 * Per questo non usa lo stack FormRequest/Action/Resource (riservato al dominio).
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
