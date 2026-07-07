<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional machine-to-machine auth. When no key is configured the middleware is
 * a no-op (dev/tests stay open); when a key is set, every request must present a
 * matching `X-Api-Key` header or gets 401. Constant-time compare avoids leaks.
 */
class ApiKey
{
    private const HEADER = 'X-Api-Key';

    /**
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('gamindo.api_key', '');

        if ($expected === '') {
            return $next($request);
        }

        $provided = (string) $request->header(self::HEADER, '');
        if (! hash_equals($expected, $provided)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid or missing API key.');
        }

        return $next($request);
    }
}
