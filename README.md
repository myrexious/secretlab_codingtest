# Secretlab Key-Value Store

[![CI](https://github.com/myrexious/secretlab_codingtest/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/myrexious/secretlab_codingtest/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/myrexious/secretlab_codingtest/branch/master/graph/badge.svg)](https://codecov.io/gh/myrexious/secretlab_codingtest)

A **version-controlled** key-value store with an HTTP API.

The store is append-only. A write never overwrites a value; it appends a new
version. "The latest value" and "the value at time T" are therefore the same
query with a different upper bound.

- **Live API:** https://secretlab.kreio.tech
- **Interactive docs:** https://secretlab.kreio.tech/docs
- **Health:** https://secretlab.kreio.tech/health

Laravel 12 · PHP 8.4 · PostgreSQL 16 · Redis 7 · FrankenPHP · Docker

---

## Try it in 30 seconds

Open **[/docs](https://secretlab.kreio.tech/docs)**, press **Authorize**, and paste
the public demo key:

```
demo-secretlab-kv-store-public-readonly-key
```

The key is optional. Without it you are rate-limited by IP address instead.

```bash
BASE=https://secretlab.kreio.tech

# Store a value
curl -X POST $BASE/kv-value/data -H 'Content-Type: application/json' \
     -d '{"key":"mykey","value":{"colour":"blue"}}'
# -> {"key":"mykey","value":{"colour":"blue"},"timestamp":1791384647.486129}

# Store a second version
curl -X POST $BASE/kv-value/data -H 'Content-Type: application/json' \
     -d '{"key":"mykey","value":[1,2,3]}'

# The latest value
curl $BASE/kv-value/data/mykey
# -> {"key":"mykey","value":[1,2,3],"timestamp":...}

# The value as it was at the first write
curl "$BASE/kv-value/data/mykey?timestamp=1791384647.486129"
# -> {"key":"mykey","value":{"colour":"blue"},"timestamp":1791384647.486129}

# Every version of this key
curl $BASE/kv-value/history/mykey

# Every key with its current value
curl $BASE/kv-value/get-all-keys
```

## API

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/kv-value/data` | Store one value |
| `POST` | `/kv-value/bulk-create` | Store up to 50 values atomically |
| `GET` | `/kv-value/data/{key}` | The latest value |
| `GET` | `/kv-value/data/{key}?timestamp=` | The value at or before that time |
| `GET` | `/kv-value/data/{key}?direct=true` | The latest value, skipping the cache |
| `GET` | `/kv-value/history/{key}` | Every version of one key, paginated |
| `GET` | `/kv-value/get-all-keys` | Every key with its current value, paginated |
| `GET` | `/health` | Liveness probe, never rate-limited |
| `GET` | `/docs` | Swagger UI |

Full request and response schemas live in [`public/openapi.yaml`](public/openapi.yaml).

### Limits

| | |
|---|---|
| Key | `[A-Za-z0-9._:-]`, up to 255 characters |
| Value | Any JSON document, up to 256 KiB |
| Bulk | 50 pairs per request |
| Page size | 50 by default, 200 maximum |
| Rate limit | 120/min anonymous, 600/min per key, 300/min for the demo key |

---

## Run it locally

Docker is the only requirement. There is no need for PHP or Composer on the host.

```bash
git clone https://github.com/myrexious/secretlab_codingtest.git
cd secretlab_codingtest

cp .env.example .env
# Point at the bundled database and set a local Redis password
sed -i 's/^DB_HOST=.*/DB_HOST=postgres/;s/^DB_PASSWORD=.*/DB_PASSWORD=kvstore/;s/^REDIS_PASSWORD=.*/REDIS_PASSWORD=devpassword/' .env

docker compose -f compose.dev.yml up -d --build
docker compose -f compose.dev.yml exec app php artisan key:generate
docker compose -f compose.dev.yml exec app php artisan migrate --seed
```

The API is then on <http://localhost:8088>, and the docs on
<http://localhost:8088/docs>.

`compose.dev.yml` brings its own PostgreSQL and Redis. `compose.yml` is the
production stack: it carries Traefik labels, publishes no ports, and connects to
PostgreSQL running natively on the host.

### Tests

```bash
docker compose -f compose.dev.yml exec app ./vendor/bin/pest
docker compose -f compose.dev.yml exec app ./vendor/bin/pest --coverage
docker compose -f compose.dev.yml exec app ./vendor/bin/pint
```

157 tests, 100% line coverage of `app/`.

### Mint an API key

```bash
docker compose -f compose.dev.yml exec app php artisan kv:client:create "my app"
docker compose -f compose.dev.yml exec app php artisan kv:client:create "slow app" --limit=60
```

The key is printed once. Only its SHA-256 hash is stored, so a leaked database
dump yields no usable credentials.

---

## Load test

[`loadtest/k6.js`](loadtest/k6.js) is a [k6](https://k6.io) script. It sends 50% writes and
50% reads. The rate steps up from 50 to 1,600 requests per second, with 2 minutes per step.
The test stops when p95 latency is above 1 second or errors are above 1%. The last step that
passed is the capacity.

1. Mint a key with a high limit: `kv:client:create "load-testing" --limit=600000`.
2. Run the script from a machine other than the server:

```bash
K6_KEY=<load-testing api key> k6 run loadtest/k6.js
```

A test against production writes keys that start with `loadtest:`. The store is
append-only, so delete those rows and the client after the test.

### Results

Run against production (`secretlab.kreio.tech`), a small VPS behind Cloudflare,
from a single client over the public internet. 50% writes, 50% reads, ramping
arrival rate.

This run was configured to stop at **15,000 requests, or when p95 latency
reached 2 s, whichever came first**. The 2 s threshold was reached first, so the
test stopped itself there by design. The service did not fail — 2 s was the
chosen cut-off, not a breaking point.

| Metric | Value |
|---|---|
| Requests sent | 7,570 |
| **Failed requests** | **0.00%** (0 of 7,570) |
| Checks passed | 100.00% (7,566 of 7,566) |
| Median latency (p50) | 78 ms |
| p90 latency | 1.13 s |
| p95 latency | 2.06 s |
| Max latency | 3.08 s |
| Stop condition | p95 reached the configured 2 s limit at ~100 req/s (before the 15,000-request cap) |

**The service does not break under load.** Zero errors across the whole run, and
a flat 78 ms median. It degrades gracefully: as the rate nears 100 req/s the
tail latency rises and requests queue, but nothing fails and nothing is
rejected.

The comfortable ceiling at this vantage is around 50 req/s. Two factors sit
between this number and the raw origin capacity, and both inflate the tail:

- The client, the public internet, and Cloudflare are all in the path. The 50 ms
  minimum latency is network floor, before the app does any work.
- The workload is write-heavy. A write holds a worker longer than a cached read,
  so a read-dominant workload — the typical shape for a key-value store — would
  sustain a much higher rate.

The limiting factor is tail latency under concurrency, not correctness or
errors.

---

## Design notes

The full reasoning lives in [`plans/01-application.md`](plans/01-application.md)
and [`plans/02-deployment.md`](plans/02-deployment.md). The decisions worth
knowing up front:

**The value column is `json`, not `jsonb`.** `jsonb` is the usual default
because it is fast to query *inside* a document. This service never queries
inside a value; it stores one and hands it back. `jsonb` would discard object
key order for an indexing benefit that is never used. `text` would be worse than
both: it accepts any bytes, so one malformed write would poison every later read
of that key. A JSON column rejects bad input at write time, so a read can never
fail to decode.

**The database assigns the timestamp, not PHP.** Several app containers can run
at once and their clocks drift apart. A PHP timestamp can therefore order two
writes wrongly because two machines disagree about the time. The column default
is `clock_timestamp()` and not `now()` — `now()` returns the *transaction* start
time and stays frozen, which would make every row of a bulk insert collide.

**Timestamps are decimal UNIX seconds, with microseconds.** The PRD example
shows a whole-second UNIX timestamp (`1440568980`). This service accepts that
form on input, and also accepts a decimal. It always *returns* a decimal
(`1440568980.123456`). The reason is correctness, not decoration: two writes to
one key 10 ms apart would render identically at whole-second precision, and no
query could then reach the earlier one. A `UNIQUE (key, recorded_at)` constraint
guarantees one key never holds two records at one timestamp. The value is still
a UNIX timestamp in seconds, UTC — the exercise's stated contract — with the
sub-second part exposed so every version stays addressable.

**A miss is 404, never `"value": null`.** `null` is a storable value, so
reporting absence as a null value would make the two indistinguishable.

**`data/` and `history/` prefix the key-bearing paths.** No literal route can
then shadow a key, so there are no reserved key names. A key named
`get-all-keys` works exactly like any other.

**Redis backs the cache and the rate limiter.** Laravel's file driver has no
atomic increment — its `increment()` reads, modifies and writes with no lock, so
counters drift low under exactly the concurrent load a rate limiter exists for.

**The API key is optional.** A mandatory key would answer 401 to reviewers who
do not have one. A key that is *present but unknown* is still rejected with 401,
because silently downgrading it would hide a client misconfiguration.

**List routes are paginated with cursors.** An unpaginated list is the most
likely way a service like this falls over under load, and it works perfectly on
a development machine with 12 rows. Cursors rather than offsets, so a page stays
correct while writes land mid-scan.

---

## Deployment

Push to `master`. GitHub Actions runs the suite against a throwaway PostgreSQL,
builds the image, pushes it to GHCR, then connects to the VPS over SSH. The
image that passed the tests is the image that deploys; the VPS never rebuilds.

The SSH key is restricted by a forced command in `authorized_keys`, so it can
run [`deploy/deploy.sh`](deploy/deploy.sh) and nothing else. That matters: the
deploy account is in the `docker` group, which is root-equivalent.

Host setup — the database role, the PostgreSQL listener, the Traefik route and
the DNS record — is written out step by step in
[`plans/02-deployment.md`](plans/02-deployment.md).

---

## Declaration of AI use

As the exercise requires, this is declared in full.

**Claude (Anthropic), via Claude Code, was used throughout.** It was used to
interrogate the requirements before any code was written, to draft the
implementation, and to write the tests.

The requirements were deliberately stress-tested first, over four rounds of
questions, before a line was written. Several decisions in this repository exist
because that process surfaced a problem that the brief did not state:

- Whole-second timestamps would have made some versions permanently
  unaddressable. That is why the API exposes microseconds.
- The first routing design let a key named `get-all-keys` be written but never
  read. That is why key-bearing paths carry a `data/` prefix.
- Laravel decodes request bodies into associative arrays, which cannot tell `{}`
  from `[]` and silently rewrites `{"0":"a"}` as `["a"]`. A failing test caught
  it; the raw body is now decoded a second time with objects preserved.
- Behind Traefik, `$request->ip()` returns the proxy's address, which would have
  put every anonymous caller in one shared rate-limit bucket.

Every design decision, the trade-offs behind it, and the alternatives rejected
are recorded in [`plans/`](plans/). The author directed the work, made the
calls, and is responsible for the result.
