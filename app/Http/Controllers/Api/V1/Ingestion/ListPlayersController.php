<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ingestion\PlayerResource;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lists a version's players (id + identity), paginated. Read-only and thin: no
 * Action needed. Exposes the ids consumers need to attach events to players.
 */
class ListPlayersController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE = 200;

    /**
     * List players
     *
     * Paginated list of a version's players with their identity (email).
     *
     * @group Ingestion
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     * @queryParam per_page integer Rows per page (1..200, default 50). Example: 50
     *
     * @response 200 {"data":[{"id":42,"email":"x@a.com","registered_at":"2026-01-01T00:00:00+00:00","total_score":10,"language":"it"}]}
     */
    public function __invoke(Request $request, Version $version): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $perPage = max(1, $perPage);

        $players = Player::forVersion((int) $version->id)
            ->with('user')
            ->orderBy('id')
            ->paginate($perPage);

        return PlayerResource::collection($players);
    }
}
