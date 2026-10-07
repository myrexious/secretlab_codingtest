<?php

return [
    /*
    | A Key may not contain "/". Laravel route parameters stop at a slash, so a
    | Key with a slash would break GET /kv-value/data/{key}. Validating the
    | charset on write means the route always works.
    */
    'key_pattern' => '[A-Za-z0-9._:-]+',
    'key_max_length' => 255,

    'value_max_bytes' => 256 * 1024,
    'bulk_max_pairs' => 50,

    /*
    | An unpaginated list route is the most likely way this service falls over
    | under load. It also works perfectly on a dev machine with 12 rows.
    */
    'page_size_default' => 50,
    'page_size_max' => 200,

    /*
    | A create evicts the cached entry for its Key, so this TTL is only a
    | backstop behind that eviction.
    */
    'cache_ttl' => (int) env('KV_CACHE_TTL', 60),

    'rate_limit' => [
        'anonymous' => (int) env('KV_RATE_LIMIT_ANONYMOUS', 120),
        'client' => (int) env('KV_RATE_LIMIT_CLIENT', 600),
        // The demo key is published in a public README. Lower than a private
        // key: high enough to stress-test, low enough for a small VPS.
        'demo' => 300,
    ],
];
