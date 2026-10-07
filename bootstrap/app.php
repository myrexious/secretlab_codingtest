<?php

use App\Http\Middleware\BlockCrawlers;
use App\Http\Middleware\ResolveApiClient;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // API-only service. There are no web routes, so there is no session
        // middleware and no CSRF. apiPrefix is empty because the paths already
        // carry their own /kv-value prefix.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters. ResolveApiClient must run FIRST, because throttle:api
        // keys its counter on the client that middleware resolves. Reverse them
        // and every request would be limited by IP address.
        $middleware->api(prepend: [
            // First line: turn away declared crawlers before any other work.
            BlockCrawlers::class,
            ResolveApiClient::class,
            'throttle:api',
        ]);

        /*
        | Without this, $request->ip() returns TRAEFIK's address, so every
        | anonymous caller would share one rate-limit bucket and one noisy
        | client could 429 the whole internet. It also fixes https:// in
        | generated URLs.
        |
        | "*" is safe here specifically because the container publishes no
        | ports. Traefik on the internal Docker network is the only thing that
        | can reach it, so there is no path by which a client could forge
        | X-Forwarded-For.
        */
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON for every error, not only when the caller sends
        // Accept: application/json. A plain curl would otherwise get an HTML
        // error page from a JSON API.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Laravel's default 429 body is "Too Many Attempts." and says nothing
        // about which limit was hit or what to do next.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $headers = $e->getHeaders();
            $retryAfter = (int) ($headers['Retry-After'] ?? 60);

            $advice = $request->header('X-API-Key')
                ? 'This limit applies to your API key.'
                : 'Send an X-API-Key header to use the higher per-key limit.';

            return response()->json([
                'message' => sprintf(
                    'Rate limit of %s requests per minute exceeded. Retry in %d second%s. %s',
                    $headers['X-RateLimit-Limit'] ?? '?',
                    $retryAfter,
                    $retryAfter === 1 ? '' : 's',
                    $advice,
                ),
            ], 429, $headers);
        });
    })->create();
