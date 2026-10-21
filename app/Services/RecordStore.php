<?php

namespace App\Services;

use App\Support\Cursor;
use App\Support\RawJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every read and write of the append-only record log.
 *
 * There is deliberately no Eloquent model. A model would need `value::text`
 * selected by hand and `$casts` left empty forever, and the first person to add
 * `protected $casts = ['value' => 'array']` would destroy the fidelity
 * guarantee with a change that looks like a tidy-up. Explicit SQL keeps the
 * requirement visible at every call site.
 */
final class RecordStore
{
    private const COLUMNS = 'key, value::text as value, extract(epoch from effective_at)::text as ts';

    /**
     * Same envelope, plus what only an editor may see. cancelled is cast to
     * text ('true'/'false'): the PDO pgsql driver's native boolean
     * representation varies, and every other signal in this class already
     * travels as text for the same reason timestamps do.
     */
    private const EDITOR_COLUMNS = self::COLUMNS
        .', extract(epoch from recorded_at)::text as recorded_ts'
        .', exists(select 1 from cancelled_schedules c '
        .'where c.key = r.key and c.effective_at = r.effective_at)::text as cancelled';

    /**
     * PostgreSQL has no literal for "timestamptz from a decimal epoch".
     * to_timestamp() would work but takes a double; numeric keeps every
     * microsecond exact. The right side stays a constant expression, so the
     * index on (key, effective_at) is still used.
     */
    private const FROM_EPOCH = "(timestamptz 'epoch' + (?::numeric) * interval '1 second')";

    /**
     * A row is live only while its (key, effective_at) was never cancelled.
     * Cancellation targets the whole slot, not one row, because two edits can
     * share a go-live instant and both must be suppressed together.
     */
    private const NOT_CANCELLED = 'not exists ('
        .'select 1 from cancelled_schedules c where c.key = r.key and c.effective_at = r.effective_at'
        .')';

    private const CACHE_PREFIX = 'kv:latest:';

    public function create(string $key, string $rawValue, ?string $effectiveAtEpoch = null): string
    {
        /*
         | The unique index on (key, recorded_at) can only collide when two
         | writes to one key land in the same microsecond. clock_timestamp() is
         | re-read on the retry, so one retry is enough. A second collision
         | would mean something is badly wrong, and throwing is correct.
         |
         | effective_at is independent: when the caller supplies one it is used
         | as given (already validated to be in the future); when they don't,
         | it defaults to this insert's own clock_timestamp() reading, which is
         | "now" for every purpose a caller could observe. It is not required to
         | equal recorded_at bit-for-bit -- both are "now" to the microsecond,
         | and nothing depends on them being identical.
         */
        $insert = fn (): object => DB::selectOne(
            'insert into records (key, value, effective_at) values (?, ?::json, coalesce('
                .self::FROM_EPOCH.', clock_timestamp())) returning '.self::COLUMNS,
            [$key, $rawValue, $effectiveAtEpoch],
        );

        for ($attempt = 0; ; $attempt++) {
            try {
                /*
                 | A constraint violation poisons the whole PostgreSQL
                 | transaction: every later statement fails until a rollback. So
                 | if a caller has already opened one, the attempt needs its own
                 | savepoint, which is what a nested DB::transaction() creates.
                 | Outside a transaction that wrapper would only add a BEGIN and
                 | COMMIT around a single insert, so it is skipped.
                 */
                $row = DB::transactionLevel() > 0 ? DB::transaction($insert) : $insert();

                Cache::forget(self::CACHE_PREFIX.$key);

                return RawJson::envelope($row->key, $row->value, $row->ts);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 1) {
                    throw $e;
                }

                usleep(1_000);
            }
        }
    }

    /**
     * One statement inside one transaction, so a bulk request is all-or-nothing.
     * Each row gets its own clock_timestamp(), because it is written directly
     * into that row's VALUES tuple rather than read from a shared default.
     *
     * @param  list<array{key: string, value: string, effective_at: ?string}>  $pairs
     * @return list<string>
     */
    public function createMany(array $pairs): array
    {
        return DB::transaction(function () use ($pairs) {
            $placeholders = implode(',', array_fill(
                0,
                count($pairs),
                '(?, ?::json, coalesce('.self::FROM_EPOCH.', clock_timestamp()))',
            ));

            $bindings = [];
            foreach ($pairs as $pair) {
                $bindings[] = $pair['key'];
                $bindings[] = $pair['value'];
                $bindings[] = $pair['effective_at'];
            }

            $rows = DB::select(
                'insert into records (key, value, effective_at) values '.$placeholders.' returning '.self::COLUMNS,
                $bindings,
            );

            foreach ($pairs as $pair) {
                Cache::forget(self::CACHE_PREFIX.$pair['key']);
            }

            return array_map($this->toEnvelope(...), $rows);
        });
    }

    /**
     * What's live right now, by the database's own clock.
     *
     * clock_timestamp(), not now(): a request can run inside a longer-lived
     * transaction (tests wrap each one in a transaction; bulk create wraps
     * its own statement), and now() freezes at that transaction's start for
     * its whole duration while clock_timestamp() keeps advancing. now() here
     * would make a row just inserted look like it is still in the future.
     */
    public function liveNow(string $key): ?string
    {
        return $this->resolveRow(
            'select '.self::COLUMNS.' from records r '.
            'where key = ? and effective_at <= clock_timestamp() and '.self::NOT_CANCELLED.' '.
            'order by effective_at desc, recorded_at desc limit 1',
            [$key],
        );
    }

    /** What was/will be live at $epoch. For an editor: may look into the future. */
    public function liveAt(string $key, string $epoch): ?string
    {
        return $this->resolveRow(
            'select '.self::COLUMNS.' from records r '.
            'where key = ? and effective_at <= '.self::FROM_EPOCH.' and '.self::NOT_CANCELLED.' '.
            'order by effective_at desc, recorded_at desc limit 1',
            [$key, $epoch],
        );
    }

    /** Same as liveAt(), but a customer may never look past the real current instant. */
    public function liveAtCapped(string $key, string $epoch): ?string
    {
        return $this->resolveRow(
            'select '.self::COLUMNS.' from records r '.
            'where key = ? and effective_at <= least('.self::FROM_EPOCH.', clock_timestamp()) '.
            'and '.self::NOT_CANCELLED.' '.
            'order by effective_at desc, recorded_at desc limit 1',
            [$key, $epoch],
        );
    }

    /**
     * The cached "live now" read.
     *
     * Cache::remember cannot do this, because its TTL is fixed before the
     * callback runs. A value can go live with no write at the moment it does,
     * so a flat TTL could serve a stale answer for up to its full length past
     * that moment. Instead the TTL for THIS population is capped to the time
     * left until the key's nearest still-pending, non-cancelled schedule --
     * computed by the database's own clock, never PHP's -- so a key with
     * nothing scheduled keeps the full backstop, and one approaching a go-live
     * moment gets an ever-shrinking TTL that lands on it with ~0 staleness.
     * See docs/adr/0002.
     */
    public function latestCached(string $key): ?string
    {
        $cacheKey = self::CACHE_PREFIX.$key;
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $value = $this->liveNow($key);

        if ($value === null) {
            return null;
        }

        $ttl = (int) config('kv.cache_ttl');
        $secondsUntilNext = $this->secondsUntilNextSchedule($key);

        if ($secondsUntilNext !== null) {
            $ttl = min($ttl, (int) floor($secondsUntilNext));
        }

        if ($ttl > 0) {
            Cache::put($cacheKey, $value, $ttl);
        }

        return $value;
    }

    /** Seconds (by the database's clock) until the key's next pending go-live, or null if none. */
    private function secondsUntilNextSchedule(string $key): ?float
    {
        $row = DB::selectOne(
            'select extract(epoch from (effective_at - clock_timestamp()))::float8 as s from records r '.
            'where key = ? and effective_at > clock_timestamp() and '.self::NOT_CANCELLED.' '.
            'order by effective_at asc limit 1',
            [$key],
        );

        return $row?->s;
    }

    /**
     * Whether $epoch is still in the future, by the database's own clock.
     * Create and Cancel both gate on this (one rejects a backdated schedule,
     * the other a backdated cancellation) so neither can rewrite what an
     * already-answered ?timestamp= query returned.
     */
    public function isFutureEpoch(string $epoch): bool
    {
        return DB::selectOne('select ('.self::FROM_EPOCH.' > clock_timestamp())::text as ok', [$epoch])->ok === 'true';
    }

    /** Whether some row targets $key at exactly $epoch (cancel's 404 vs. a real no-op). */
    public function isScheduledAt(string $key, string $epoch): bool
    {
        return DB::selectOne(
            'select 1 from records where key = ? and effective_at = '.self::FROM_EPOCH,
            [$key, $epoch],
        ) !== null;
    }

    /**
     * Withdraw whatever is scheduled for $key at $epoch, before it ever goes
     * live. Every row sharing that exact (key, effective_at) is suppressed,
     * not just the one an editor currently sees winning the slot. Calling
     * this twice for the same slot is a no-op: the second call reports the
     * original cancelled_at rather than erroring.
     */
    public function cancel(string $key, string $epoch): string
    {
        $row = DB::selectOne(
            'insert into cancelled_schedules (key, effective_at) values (?, '.self::FROM_EPOCH.') '.
            'on conflict (key, effective_at) do update set key = excluded.key '.
            'returning key, extract(epoch from effective_at)::text as effective_at, '.
            'extract(epoch from cancelled_at)::text as cancelled_at',
            [$key, $epoch],
        );

        Cache::forget(self::CACHE_PREFIX.$key);

        return '{"key":'.json_encode($row->key, RawJson::ENCODE_FLAGS)
            .',"effective_at":'.$row->effective_at
            .',"cancelled_at":'.$row->cancelled_at
            .'}';
    }

    /** @return array{0: list<string>, 1: ?string} */
    public function history(string $key, int $limit, ?string $cursor, bool $editor): array
    {
        $columns = $editor ? self::EDITOR_COLUMNS : self::COLUMNS;
        $sql = 'select '.$columns.' from records r where key = ?';
        $bindings = [$key];

        if (! $editor) {
            $sql .= ' and effective_at <= clock_timestamp() and '.self::NOT_CANCELLED;
        }

        if ($cursor !== null) {
            $sql .= ' and recorded_at < '.self::FROM_EPOCH;
            $bindings[] = $cursor;
        }

        $rows = DB::select($sql.' order by recorded_at desc limit '.($limit + 1), $bindings);

        return $this->paginate($rows, $limit, fn ($row) => $row->ts);
    }

    /** The epoch "now" is at, by the database's own clock -- never PHP's. */
    public function nowEpoch(): string
    {
        return DB::selectOne('select extract(epoch from clock_timestamp())::text as e')->e;
    }

    /**
     * @return array{0: list<string>, 1: ?string}
     *
     * $capEpoch is a concrete number, not the word "now": a multi-page walk
     * must resolve every page against the exact same instant to stay stable,
     * so the caller resolves "now" once (nowEpoch()) and that same number
     * travels through every next_cursor for the rest of the walk.
     */
    public function allKeys(int $limit, ?string $keyCursor, string $capEpoch, bool $editor): array
    {
        $cap = $editor ? self::FROM_EPOCH : 'least('.self::FROM_EPOCH.', clock_timestamp())';
        $sql = 'select distinct on (key) '.self::COLUMNS.' from records r '.
            'where effective_at <= '.$cap.' and '.self::NOT_CANCELLED;
        $bindings = [$capEpoch];

        if ($keyCursor !== null) {
            $sql .= ' and key > ?';
            $bindings[] = $keyCursor;
        }

        // DISTINCT ON (key) keeps the first row of each key group, so the
        // ORDER BY must start with key. effective_at DESC then picks the live
        // one, recorded_at DESC breaks a tie between two rows at one instant.
        $rows = DB::select(
            $sql.' order by key, effective_at desc, recorded_at desc limit '.($limit + 1),
            $bindings,
        );

        // The cursor carries the snapshot instant alongside the key position,
        // so the next page reuses it even if the caller never repeats ?timestamp=.
        return $this->paginate($rows, $limit, fn ($row) => $row->key.'|'.$capEpoch);
    }

    /**
     * Only called on a 404 path, to tell "no such key" apart from "that key
     * held nothing a caller of this kind could see at that time". Both answer
     * 404; the message differs.
     */
    public function exists(string $key, bool $editor): bool
    {
        $sql = 'select 1 from records r where key = ?';

        if (! $editor) {
            $sql .= ' and effective_at <= clock_timestamp() and '.self::NOT_CANCELLED;
        }

        return DB::selectOne($sql.' limit 1', [$key]) !== null;
    }

    private function resolveRow(string $sql, array $bindings): ?string
    {
        $row = DB::selectOne($sql, $bindings);

        return $row === null ? null : $this->toEnvelope($row);
    }

    private function toEnvelope(object $row): string
    {
        return isset($row->cancelled)
            ? RawJson::editorEnvelope($row->key, $row->value, $row->ts, $row->recorded_ts, $row->cancelled === 'true')
            : RawJson::envelope($row->key, $row->value, $row->ts);
    }

    /**
     * Fetching limit+1 rows is how we learn another page exists without a
     * second COUNT over the whole table.
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private function paginate(array $rows, int $limit, callable $cursorOf): array
    {
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $next = $hasMore && $rows !== [] ? Cursor::encode($cursorOf(end($rows))) : null;

        return [array_map($this->toEnvelope(...), $rows), $next];
    }
}
