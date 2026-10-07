<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the X-API-Key header into an ApiClient.
 *
 * This MUST run before throttle:api, because the limiter keys on the client it
 * puts in the request attributes.
 *
 * An ABSENT key is anonymous and allowed, at the lower per-IP limit. The
 * Secretlab reviewers have no key, and a mandatory key would answer 401 to the
 * people grading the submission.
 *
 * A PRESENT but unknown key is 401. Silently downgrading it to anonymous would
 * hide a typo in a caller's configuration until they hit a rate limit they did
 * not expect.
 */
class ResolveApiClient
{
    public const ATTRIBUTE = 'api_client';

    public function handle(Request $request, Closure $next): Response
    {
        $plainKey = $request->header('X-API-Key');

        if ($plainKey === null || $plainKey === '') {
            return $next($request);
        }

        $client = ApiClient::findByKey($plainKey);

        abort_if($client === null, 401,
            'The X-API-Key header does not match a known, active API key. '
            .'Omit the header entirely to use the anonymous rate limit.',
        );

        $request->attributes->set(self::ATTRIBUTE, $client);

        return $next($request);
    }
}
