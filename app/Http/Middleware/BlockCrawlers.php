<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject known crawlers and bots by user-agent.
 *
 * robots.txt only asks polite crawlers to stay away. This enforces it, so a
 * crawler that ignores robots.txt still gets a 403.
 *
 * Deliberately a curated blocklist, not a generic one. A real API client sends
 * a bot-like agent too: curl, Postman, wget, or none at all. Blocking those
 * would reject the very reviewers this service is built for. A determined
 * scraper can forge any agent, so edge defences (Cloudflare Bot Fight Mode) are
 * the real control; this covers the declared, well-behaved-but-unwanted
 * crawlers that make up the bulk of automated traffic.
 */
class BlockCrawlers
{
    public function handle(Request $request, Closure $next): Response
    {
        $agent = strtolower($request->userAgent() ?? '');

        foreach (config('kv.blocked_agents', []) as $needle) {
            if ($agent !== '' && str_contains($agent, $needle)) {
                abort(403, 'Automated crawling of this API is not allowed.');
            }
        }

        return $next($request);
    }
}
