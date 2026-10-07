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
    private const COLUMNS = 'key, value::text as value, extract(epoch from recorded_at)::text as ts';

    /**
     * PostgreSQL has no literal for "timestamptz from a decimal epoch".
     * to_timestamp() would work but takes a double; numeric keeps every
     * microsecond exact. The right side stays a constant expression, so the
     * index on (key, recorded_at) is still used.
     */
    private const FROM_EPOCH = "(timestamptz 'epoch' + (?::numeric) * interval '1 second')";

    private const CACHE_PREFIX = 'kv:latest:';

    public function create(string $key, string $rawValue): string
    {
        /*
         | The unique index on (key, recorded_at) can only collide when two
         | writes to one key land in the same microsecond. clock_timestamp() is
         | re-read on the retry, so one retry is enough. A second collision
         | would mean something is badly wrong, and throwing is correct.
         */
        $insert = fn (): object => DB::selectOne(
            'insert into records (key, value) values (?, ?::json) returning '.self::COLUMNS,
            [$key, $rawValue],
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
     * Each row gets its own clock_timestamp(), because the column default is a
     * volatile expression evaluated per row. Duplicate keys inside one request
     * are rejected by validation, so they cannot collide here.
     *
     * @param  list<array{key: string, value: string}>  $pairs
     * @return list<string>
     */
    public function createMany(array $pairs): array
    {
        return DB::transaction(function () use ($pairs) {
            $placeholders = implode(',', array_fill(0, count($pairs), '(?, ?::json)'));

            $bindings = [];
            foreach ($pairs as $pair) {
                $bindings[] = $pair['key'];
                $bindings[] = $pair['value'];
            }

            $rows = DB::select(
                'insert into records (key, value) values '.$placeholders.' returning '.self::COLUMNS,
                $bindings,
            );

            foreach ($pairs as $pair) {
                Cache::forget(self::CACHE_PREFIX.$pair['key']);
            }

            return array_map($this->toEnvelope(...), $rows);
        });
    }

    public function latest(string $key): ?string
    {
        $row = DB::selectOne(
            'select '.self::COLUMNS.' from records where key = ? order by recorded_at desc limit 1',
            [$key],
        );

        return $row === null ? null : $this->toEnvelope($row);
    }

    /**
     * The cached latest read.
     *
     * Only this read is cached. An as-of read is already immutable, so caching
     * it would grow the cache without ever saving a repeat. The list routes are
     * not cached either: they change whenever any key changes.
     *
     * Cache::remember does not store a null, so a miss is never cached and a
     * key written later is picked up at once. Every write to a key evicts its
     * entry, so the TTL is only a backstop.
     */
    public function latestCached(string $key): ?string
    {
        return Cache::remember(
            self::CACHE_PREFIX.$key,
            (int) config('kv.cache_ttl'),
            fn (): ?string => $this->latest($key),
        );
    }

    /** The bound is inclusive: the newest record at or before $epoch. */
    public function asOf(string $key, string $epoch): ?string
    {
        $row = DB::selectOne(
            'select '.self::COLUMNS.' from records '.
            'where key = ? and recorded_at <= '.self::FROM_EPOCH.' '.
            'order by recorded_at desc limit 1',
            [$key, $epoch],
        );

        return $row === null ? null : $this->toEnvelope($row);
    }

    /** @return array{0: list<string>, 1: ?string} */
    public function history(string $key, int $limit, ?string $cursor): array
    {
        $sql = 'select '.self::COLUMNS.' from records where key = ?';
        $bindings = [$key];

        if ($cursor !== null) {
            $sql .= ' and recorded_at < '.self::FROM_EPOCH;
            $bindings[] = $cursor;
        }

        $rows = DB::select($sql.' order by recorded_at desc limit '.($limit + 1), $bindings);

        return $this->paginate($rows, $limit, fn ($row) => $row->ts);
    }

    /** @return array{0: list<string>, 1: ?string} */
    public function allKeys(int $limit, ?string $cursor): array
    {
        $sql = 'select distinct on (key) '.self::COLUMNS.' from records';
        $bindings = [];

        if ($cursor !== null) {
            $sql .= ' where key > ?';
            $bindings[] = $cursor;
        }

        // DISTINCT ON (key) keeps the first row of each key group, so the
        // ORDER BY must start with key. recorded_at DESC then picks the latest.
        $rows = DB::select($sql.' order by key, recorded_at desc limit '.($limit + 1), $bindings);

        return $this->paginate($rows, $limit, fn ($row) => $row->key);
    }

    /**
     * Only called on a 404 path, to tell "no such key" apart from "that key
     * held nothing yet at that time". Both answer 404; the message differs.
     */
    public function exists(string $key): bool
    {
        return DB::selectOne('select 1 from records where key = ? limit 1', [$key]) !== null;
    }

    private function toEnvelope(object $row): string
    {
        return RawJson::envelope($row->key, $row->value, $row->ts);
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
