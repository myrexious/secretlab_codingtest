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

    /*
    | Cloudflare edge ranges, from https://www.cloudflare.com/ips/. Traefik
    | does not trust Cloudflare, so it replaces X-Forwarded-For with the edge
    | address. The real client IP survives only in CF-Connecting-IP, which the
    | rate limiter honours when the peer is inside one of these ranges.
    | ponytail: hand-copied list, refresh from the URL above if Cloudflare adds ranges.
    */
    'cloudflare_ranges' => [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ],

    'rate_limit' => [
        'anonymous' => (int) env('KV_RATE_LIMIT_ANONYMOUS', 120),
        'client' => (int) env('KV_RATE_LIMIT_CLIENT', 600),
        // The demo key is published in a public README. Lower than a private
        // key: high enough to stress-test, low enough for a small VPS.
        'demo' => 300,
    ],
];
