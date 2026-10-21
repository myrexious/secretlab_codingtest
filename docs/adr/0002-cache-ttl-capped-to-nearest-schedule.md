# Cap the latest-value cache TTL to the nearest pending schedule

`latestCached()` serves the default (no `?timestamp=`) read from a Redis entry evicted on write, with a 60s TTL as a backstop (`config/kv.php`). A scheduled value going live involves no write at the moment it takes effect, so an entry cached just before go-live could serve stale data for up to 60s past it — breaking the "live within 1 second, nothing pressed" requirement.

Rejected: disabling the cache outright (correct, but pays the DB-hit cost on every read for the overwhelming majority of keys that have nothing scheduled). Rejected: a flat sub-second TTL everywhere (same cost, worse, for no reason most of the time).

Decided: when (re)populating the cache entry, also look up the key's nearest not-cancelled future `effective_at` (same index the resolution query already uses) and cap that write's TTL to `min(configured_ttl, time_until_that_instant)`. A key with nothing scheduled keeps the full 60s benefit; a key approaching a go-live moment gets a shrinking TTL that lands on the boundary with ~0 staleness, and each natural expiry after that re-checks and re-caps.
