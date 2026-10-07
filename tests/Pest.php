<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| RefreshDatabase wraps each test in a transaction and rolls it back.
| clock_timestamp() keeps advancing inside a transaction, unlike now(), so the
| ordering behaviour under test still holds.
|
| The cache flush matters because the rate limiter stores its counters there.
| Without it, one test's 429 leaks into the next.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Cache::flush())
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * POST a raw JSON body.
 *
 * postJson() encodes a PHP array, which destroys information before the request
 * is even sent: a float 1.0 becomes 1, and {} versus [] is decided by PHP's
 * types rather than by the caller. A fidelity test has to control the exact
 * bytes on the wire.
 */
function postRaw(string $uri, string $json): TestResponse
{
    return test()->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $json);
}
