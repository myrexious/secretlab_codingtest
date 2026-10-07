<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkCreateRequest;
use App\Http\Requests\CreateRecordRequest;
use App\Services\RecordStore;
use App\Support\Cursor;
use App\Support\RawJson;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class KvValueController extends Controller
{
    /** A decimal UNIX timestamp in seconds. Whole or fractional, never signed. */
    private const EPOCH_PATTERN = '/^\d{1,12}(\.\d{1,6})?$/';

    public function __construct(private readonly RecordStore $store) {}

    public function store(CreateRecordRequest $request): Response
    {
        return RawJson::response(
            $this->store->create(
                $request->validated('key'),
                RawJson::encodeForStorage($this->shapedValue($request, 'value')),
            ),
            201,
        );
    }

    public function bulkStore(BulkCreateRequest $request): Response
    {
        $pairs = [];

        foreach ($request->validated('pairs') as $index => $pair) {
            $pairs[] = [
                'key' => $pair['key'],
                'value' => RawJson::encodeForStorage($this->shapedValue($request, "pairs.{$index}.value")),
            ];
        }

        return RawJson::response(
            RawJson::collection($this->store->createMany($pairs)),
            201,
        );
    }

    /** Latest record, or the record as of ?timestamp=. */
    public function show(Request $request, string $key): Response
    {
        $request->validate(
            [
                'timestamp' => ['nullable', 'regex:'.self::EPOCH_PATTERN],
                'direct' => ['nullable', 'in:true,false,1,0'],
            ],
            [
                'timestamp.regex' => 'The timestamp ":input" is not a UNIX timestamp. Use seconds since '
                    .'the epoch, UTC, with up to six decimal places, for example 1440568980.123456.',
                'direct.in' => 'The direct flag must be true or false.',
            ],
        );

        $timestamp = $request->query('timestamp');

        if ($timestamp !== null) {
            // An as-of read is never cached: the answer is immutable, so the
            // entry would expire long before it was ever reused.
            $envelope = $this->store->asOf($key, $timestamp);
        } else {
            $direct = filter_var($request->query('direct', 'false'), FILTER_VALIDATE_BOOL);

            $envelope = $direct
                ? $this->store->latest($key)
                : $this->store->latestCached($key);
        }

        if ($envelope === null) {
            $this->notFound($key, $timestamp);
        }

        return RawJson::response($envelope);
    }

    public function history(Request $request, string $key): Response
    {
        [$limit, $cursor] = $this->pagination($request, self::EPOCH_PATTERN);

        [$envelopes, $next] = $this->store->history($key, $limit, $cursor);

        // An empty first page means the key was never written. An empty later
        // page just means the caller walked off the end, which is not an error.
        if ($envelopes === [] && $cursor === null) {
            $this->notFound($key, null);
        }

        return RawJson::response(RawJson::page($envelopes, $next));
    }

    public function allKeys(Request $request): Response
    {
        [$limit, $cursor] = $this->pagination($request, '/^'.config('kv.key_pattern').'$/');

        [$envelopes, $next] = $this->store->allKeys($limit, $cursor);

        return RawJson::response(RawJson::page($envelopes, $next));
    }

    /**
     * Pull a value out of the request with its JSON shape intact.
     *
     * Laravel decodes the body with assoc = true. An associative array cannot
     * represent the difference between {} and [], and it silently rewrites
     * {"0":"a","1":"b"} into ["a","b"]. Decoding the raw body a second time
     * with objects keeps both shapes.
     *
     * Validation has already run against Laravel's decoded copy. That is fine:
     * it only checks presence and encoded size, neither of which this changes.
     */
    private function shapedValue(Request $request, string $path): mixed
    {
        if (! $request->isJson()) {
            return $request->input($path);
        }

        // No try/catch: a malformed body was already rejected with 400 in
        // RecordRules::prepareForValidation, so this decode cannot fail.
        return data_get(json_decode($request->getContent()), $path);
    }

    /**
     * Both misses answer 404, but the message tells them apart: a key that was
     * never written, against a key that held nothing yet at that time.
     */
    private function notFound(string $key, ?string $timestamp): never
    {
        throw new NotFoundHttpException(
            $timestamp !== null && $this->store->exists($key)
                ? sprintf('The key "%s" exists, but no value was stored at or before timestamp %s.', $key, $timestamp)
                : sprintf('No value has ever been stored for the key "%s".', $key),
        );
    }

    /**
     * @param  string  $cursorPattern  What the DECODED cursor must look like.
     *                                 Without this check a crafted cursor would
     *                                 reach PostgreSQL and fail as a 500.
     * @return array{0: int, 1: ?string}
     */
    private function pagination(Request $request, string $cursorPattern): array
    {
        $validated = $request->validate(
            [
                'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('kv.page_size_max')],
                'cursor' => ['nullable', 'string'],
            ],
            [
                'limit.integer' => 'The limit ":input" must be a whole number.',
                'limit.min' => 'The limit must be at least 1.',
                'limit.max' => 'The limit may not be greater than '.config('kv.page_size_max').'.',
            ],
        );

        $limit = (int) ($validated['limit'] ?? config('kv.page_size_default'));

        if (! isset($validated['cursor'])) {
            return [$limit, null];
        }

        $cursor = Cursor::decode($validated['cursor']);

        if ($cursor === null || preg_match($cursorPattern, $cursor) !== 1) {
            throw ValidationException::withMessages([
                'cursor' => 'The cursor is not valid. Pass the next_cursor value from a previous page unchanged.',
            ]);
        }

        return [$limit, $cursor];
    }
}
