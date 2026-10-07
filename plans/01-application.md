# 01 — Application Plan

Secretlab Tech Exercise: a version-controlled key-value store.

This file covers the application. The deployment lives in
[`02-deployment.md`](./02-deployment.md).

## Context

Secretlab asks for a version-controlled key-value store with an HTTP API. The
store never overwrites a value. Each write appends a new version. The API
returns the latest value, or the value as it was at a past time.

The PRD text is the requirement. The request and response bodies in the PRD are
illustrations only. They do not fix the API shape.

**PRD constraints:** PHP with Laravel. A database of our choice. Unit tests and
CI/CD that watch the master branch. A public deployment URL. A public git
repository. A build status badge. Code coverage reporting as a bonus.
Production-quality code that handles edge cases and survives load. A declaration
of AI tool use in the README.

**User constraints:** Deploy to `secretlab.kreio.tech` under the `/kv-value`
prefix. Build with Docker. Rate-limit each caller by API key. Cache the
latest-value read, with a direct-read escape. Connect to the user's own
PostgreSQL on the user's own VPS. Ship a Swagger page.

The repository directory is empty. This is a greenfield project.

---

## Domain model

| Term | Meaning |
|---|---|
| **Key** | A string that identifies a series of values. |
| **Value** | A JSON document stored against a Key. A bare string, number or boolean is a valid JSON document. |
| **Record** | One immutable write of a Value against a Key, at one point in time. |
| **Latest Record** | The Record for a Key with the highest `recorded_at`. |
| **As-Of Read** | A read that returns the Record for a Key at or before a given timestamp. |
| **API Client** | The caller that an API key identifies. The rate limiter counts per API Client. A caller with no key falls back to its IP address. |

Core rule: the store is **append-only**. A create always inserts a new Record. A
create never updates or deletes a Record. "Latest value" and "value at time T"
are therefore the same query with a different upper bound.

---

## 1.1 Routes

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/kv-value/data` | Create one Record. |
| `POST` | `/kv-value/bulk-create` | Create many Records. |
| `GET` | `/kv-value/data/{key}` | Read the Latest Record. |
| `GET` | `/kv-value/data/{key}?timestamp=<unix>` | As-Of Read. |
| `GET` | `/kv-value/data/{key}?direct=true` | Read the Latest Record, bypassing the cache. |
| `GET` | `/kv-value/history/{key}` | Every Record for one Key, newest first. Paginated. |
| `GET` | `/kv-value/get-all-keys` | Every Key with its Latest Record. Paginated. |
| `GET` | `/health` | Liveness probe. Checks PostgreSQL and Redis. |
| `GET` | `/docs` | Swagger UI. |

The `data/` and `history/` prefixes separate key-bearing paths from literal
paths. No literal path can shadow a Key. There are no reserved key names, and no
Key is ever unreachable.

## 1.2 Request and response bodies

Create:

```json
{ "key": "mykey", "value": <any JSON> }
```

Bulk create:

```json
{ "pairs": [ { "key": "a", "value": 1 }, { "key": "b", "value": {"x": 2} } ] }
```

Every read returns an envelope:

```json
{ "key": "mykey", "value": <any JSON>, "timestamp": 1440568980.123456 }
```

List routes return `{ "data": [ <envelope>, ... ], "next_cursor": "<string|null>" }`.

Errors use Laravel's default body: `{"message": "...", "errors": {...}}`.
Every create error must name the field, the rule, and the value that failed.

## 1.3 Data model

`records`:

| Column | Type | Note |
|---|---|---|
| `id` | `bigserial` primary key | Tiebreaker for ordering. |
| `key` | `varchar(255)` | Matches `[A-Za-z0-9._:-]{1,255}`. |
| `value` | `json` | Not `jsonb`. Not `text`. |
| `recorded_at` | `timestamptz(6)` | `DEFAULT clock_timestamp()`. |

Indexes: `UNIQUE (key, recorded_at)`, and `(key, recorded_at DESC, id DESC)`.

`api_clients`:

| Column | Note |
|---|---|
| `id` | |
| `name` | |
| `key_hash` | SHA-256, unique index. Never store the plaintext key. |
| `rate_limit_per_minute` | Per-client override. |
| `created_at` | |
| `revoked_at` | Null while active. |

### Why `json` and not `jsonb`

`jsonb` is the usual default because it is fast to query *inside*. We never query
inside a Value. We store it and return it.

| | `jsonb` | `json` |
|---|---|---|
| Validates on write | yes | yes |
| Preserves key order | no | yes |
| Preserves duplicate keys | no | yes |
| Preserves whitespace | no | yes |
| Indexable | yes | no |

`text` is worse than both. The database would accept any bytes. A malformed
write would succeed, and every later read of that Key would fail to decode. A
JSON column rejects malformed input at write time, so a read can never fail.

### Value fidelity

Return the Value with its original JSON type. A caller who sends `42` gets `42`.
A caller who sends `[1,2]` gets `[1,2]`. Never escape the Value into a string.

**This constrains the code.** An Eloquent `array` cast decodes the column to a
PHP array and re-encodes it on the way out. That re-encode destroys the fidelity
the `json` column preserves. So:

- Do not cast the `value` column.
- Read it as a raw string.
- Build the response JSON by splicing the raw string in. One private helper on
  the controller is enough.

### Why the database sets the timestamp

Several application containers can run at once. Their clocks drift apart. NTP
drift of tens of milliseconds is normal. If PHP stamps the time, two writes to
one Key can land in the wrong order because two machines disagree about the
time. The database is one clock.

Use `clock_timestamp()`, **not** `now()`. `now()` returns the transaction start
time and stays frozen for the whole transaction. The `INSERT` uses `RETURNING`
to read the stamped value back in one round trip.

Known limit: timestamp order and visibility order can differ by microseconds. A
`pg_advisory_xact_lock(hashtext(key))` removes this if it ever matters. Mark it
with a `ponytail:` comment and leave it out.

### Why the API exposes a decimal timestamp

One Key must never have two Records at one timestamp. `UNIQUE (key,
recorded_at)` enforces this in the database. Retry once if an insert conflicts.

Storage precision alone is not enough. With whole-second output, two writes 10 ms
apart both render as `1440568980`. The history list then carries two identical
timestamps, and no query can reach the earlier Record.

`1440568980.123456` needs about 2^51 of mantissa. An IEEE-754 double holds 2^53.
The value round-trips exactly through JSON in PHP and in JavaScript.

Read the value out of PostgreSQL as text, not as a float:
`EXTRACT(EPOCH FROM recorded_at)::text`. Splice that string straight into the
response JSON.

## 1.4 Read semantics

- `?timestamp=T` returns the newest Record with `recorded_at <= T`. The bound is
  inclusive. `T` accepts a whole number or a decimal.
- `T` before the first write returns **404**.
- `T` in the future returns the Latest Record.
- A Key that was never written returns **404**.

Do not signal "not found" with `"value": null`. A caller can store a literal JSON
`null`. The 404 keeps the two cases apart.

## 1.5 Pagination

Both list routes use cursor pagination: `?limit=&cursor=`. A cursor stays stable
while writes land mid-scan. An offset does not.

The cursor for `get-all-keys` is the last `key`. The cursor for `history` is the
last `id`. Default page size 50. Maximum 200. The VPS is small.

An unpaginated list route is the most likely way this service falls over under
the load the PRD grades. It also works perfectly on a development machine with
12 rows, which is why it is easy to miss.

## 1.6 Input limits

| Limit | Value |
|---|---|
| Key pattern | `[A-Za-z0-9._:-]{1,255}` |
| Value size | 256 KiB |
| Bulk pairs per request | 50 |
| Page size | 50 default, 200 maximum |

Restrict the Key charset. Do not allow `/`. Laravel route parameters stop at a
slash, so a Key with a slash breaks the read route.

Bulk create is atomic. One transaction. All pairs share one timestamp call. One
invalid pair fails the whole request with 422.

## 1.7 API clients and rate limits

- Header `X-API-Key`. Swagger UI supports this as an `apiKey` scheme, so its
  Authorize button works without extra code.
- Look the key up by SHA-256 hash. A leaked database dump then yields no usable
  keys.
- **No key** means anonymous. **A present but invalid key** returns 401. An
  explicit error beats a silent downgrade to anonymous.
- Artisan command `kv:client:create {name}` mints a key and prints it once.
- A seeder creates one demo client at 300 requests per minute. Publish that key
  in the README so reviewers can use the Swagger page at once. 300 is high
  enough to stress-test and low enough for a small VPS to survive.

| Caller | Limit |
|---|---|
| Anonymous, by IP | 120 per minute |
| Valid API key | 600 per minute |
| Public demo key | 300 per minute |

`rate_limit_per_minute` on the client row overrides the default.

Use Laravel's built-in fixed-window limiter. A fixed window allows a 2x burst
across a window edge. That is acceptable here. The `throttle` middleware emits
`X-RateLimit-Limit`, `X-RateLimit-Remaining` and `Retry-After` without extra
code.

Order matters: the `ResolveApiClient` middleware must run **before**
`throttle:api`, because the limiter keys on the resolved client.

## 1.8 Cache

- Cache the **Latest Record read only**. Do not cache the As-Of Read, because
  that result is already immutable. Do not cache the list routes.
- Bypass with `?direct=true`.
- A create for a Key evicts that Key's cached entry.
- TTL 60 seconds, as a backstop behind the eviction.
- Cache the finished envelope string, so a hit skips both the query and the
  JSON build.

## 1.9 Why Redis, and why its own container

Laravel's `file` cache driver has no atomic increment. Its `increment()` reads,
modifies and writes with no lock. A rate limiter is only increments, so the
counter undercounts under concurrent load and the limit leaks. Redis `INCR` is
atomic. The `database` driver is atomic but writes a row on every request.

Redis runs in its own container. Two processes in one container break health
reporting: PID 1 is the application, so a dead Redis still reports healthy. A
separate container also means `docker compose restart app` does not clear the
rate-limit counters.

| Setting | Value | Reason |
|---|---|---|
| `ports:` | omitted | A published port writes iptables rules that sit before the `ufw` chain, so Docker bypasses the firewall. |
| `--requirepass` | from `.env` | Defends a later network misconfiguration. |
| `--maxmemory` | `128mb` | Redis grows without a cap. On a small VPS that kills PostgreSQL first. |
| `--maxmemory-policy` | `allkeys-lru` | Every key we store carries a TTL, so this matches `volatile-lru` here. |
| `--save ""`, `--appendonly no` | persistence off | Cache entries and counters are disposable. This saves disk writes. |

## 1.10 Runtime

Use **FrankenPHP** (`dunglas/frankenphp:1-php8.3`). One image. One process. No
nginx, no PHP-FPM, no supervisor.

Classic PHP is not a standalone server. A web server takes the request and hands
it to PHP over FastCGI. That is two programs. FrankenPHP embeds PHP inside
Caddy, a Go HTTP server, so PHP becomes standalone like a Go binary.

Set `SERVER_NAME=":80"` so Caddy serves plain HTTP. Traefik terminates TLS.
FrankenPHP must not try to get its own certificate.

Versions: Laravel 12, PHP 8.4, PostgreSQL 16, Pest.

## 1.11 Documentation

Write `public/openapi.yaml` by hand. Serve Swagger UI from a `/docs` route. Do
not use `l5-swagger`. An annotation scanner is more machinery than seven
endpoints justify.

Declare the use of AI tools in the README, as the PRD requires.

## 1.12 Tests

Run feature tests against a real PostgreSQL. Hit the routes end to end.

**Do not test against SQLite.** SQLite has no `clock_timestamp()`. Its `json`
semantics differ. The suite would pass while production breaks.

Unit tests cover key validation, cursor encode and decode, and key hashing.

Named edge-case tests:

1. A stored literal `null` returns 200 with `"value": null`. A missing Key
   returns 404.
2. Two writes to one Key inside one microsecond. The unique constraint fires and
   the retry succeeds.
3. Value fidelity: `42`, `"42"`, `[1,2]`, `{"b":1,"a":2}` and `null` each return
   unchanged. Object key order survives.
4. A value over 256 KiB returns 422.
5. A 51-pair bulk request returns 422. A bulk request with one invalid pair
   writes nothing.
6. An As-Of read before the first write returns 404.
7. An As-Of read at an exact second boundary excludes later records in that
   second.
8. A Key named `get-all-keys` is writable and readable.
9. Rate limit 429 carries `Retry-After` and `X-RateLimit-*`.
10. An invalid API key returns 401. An absent key is anonymous.
11. `?direct=true` returns a value written directly to the database behind the
    cache's back.

Coverage driver: `pcov`, not `xdebug`. It is about 10 times faster.

## 1.13 Build order

1. Laravel skeleton, `compose.yml`, FrankenPHP Dockerfile, `/health`.
2. Migrations, the `Record` model with no cast, and the raw-JSON helper.
3. Create and bulk create, with validation and the unique-constraint retry.
4. Latest read, As-Of read, 404 semantics.
5. History and `get-all-keys`, with cursor pagination.
6. `api_clients`, the resolve middleware, the artisan command, the seeder.
7. Rate limits.
8. Cache, with eviction and `?direct=true`.
9. `openapi.yaml` and the `/docs` route.
10. Tests throughout, not at the end.
11. CI workflow.
12. **Codecov last.** Finish every other item first.



---

## Deviations from this plan, and why

Recorded during the build. Each one changed a decision made above.

### PHP 8.4, not 8.3

`composer create-project` ran inside the `composer:2` image, whose PHP is newer
than the runtime. It resolved Symfony 8, which needs PHP >= 8.4.1, so the lock
file could not be satisfied on 8.3.

The fix is not only the version bump. `composer.json` now pins
`config.platform.php` to `8.4.1`, so Composer always resolves for the version
that will run the code, whatever machine does the resolving.

### No Eloquent model for records

The plan called for a `Record` model with no cast. There is no model at all.

A model would need `value::text` selected by hand and `$casts` left empty
forever. The first person to add `protected $casts = ['value' => 'array']` would
destroy the fidelity guarantee with a change that looks like a tidy-up. Explicit
SQL in `RecordStore` keeps the requirement visible at every call site.

`ApiClient` is a normal Eloquent model. The rule only applies where a
caller-supplied JSON document is involved.

### No reserved key names

The round-3 route change made the guard unnecessary. With `data/` and `history/`
prefixing every key-bearing path, no literal route can shadow a key, so
`get-all-keys` and `bulk-create` are ordinary, readable keys.

### Input fidelity has a limit the plan did not state

Laravel decodes the request body before any application code runs, so a
re-encode is unavoidable. PHP's decode-encode round trip preserves type and
object key order, but not duplicate keys, not whitespace, and not integers
beyond 64 bits.

Worse, Laravel decodes with `assoc = true`. An associative array cannot
represent the difference between `{}` and `[]`, and it silently rewrites
`{"0":"a","1":"b"}` into `["a","b"]`. A failing test caught this.

`KvValueController::shapedValue()` decodes the raw body a second time with
objects preserved, which fixes both shapes. The `json` column still earns its
place: it validates at the write boundary, and it guarantees that whatever is
stored comes back byte-identical forever after.

### Malformed request bodies answer 400

Laravel decodes an unparseable JSON body to an empty array, so the caller was
told "A key is required" when the real problem was the body. A
`prepareForValidation` guard now answers 400 and says so.

This also made two `JsonException` catch blocks unreachable, because every value
reaching them now comes from a successful decode. They were deleted rather than
tested.

### The insert retry takes a savepoint

A constraint violation poisons the whole PostgreSQL transaction: every later
statement fails until a rollback. So the retry would have failed if any caller
ever wrapped `create()` in a transaction.

The attempt now runs inside a nested `DB::transaction()` when one is already
open, which issues a savepoint. Outside a transaction that wrapper is skipped,
so a normal write pays nothing for it.

### Trusted proxies

Not in the plan, and a real bug. Behind Traefik, `$request->ip()` returns
Traefik's address, so every anonymous caller would share one rate-limit bucket
and one noisy client could 429 the whole internet.

`trustProxies(at: '*')` is safe here specifically because the container
publishes no ports: Traefik on the internal Docker network is the only thing
that can reach it, so no client can forge `X-Forwarded-For`.

### Local ports

Port 8080 is held by Docker Desktop's WSL relay, and 5432 falls inside a Windows
reserved range. The development stack uses **8088** for the app and **15432**
for PostgreSQL. Production is unaffected: it publishes no ports at all.

### Two standalone compose files

`compose.yml` is production and `compose.dev.yml` is a complete, separate
development stack. They are not layered, because Compose merging is additive: an
overlay could never remove the Traefik network or drop the bundled PostgreSQL.
The duplicated Redis block is about fifteen lines, which is cheaper than a merge
scheme that cannot express what is needed.
