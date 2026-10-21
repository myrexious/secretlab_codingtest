<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveApiClient;
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
    public function __construct(private readonly RecordStore $store) {}

    public function store(CreateRecordRequest $request): Response
    {
        return RawJson::response(
            $this->store->create(
                $request->validated('key'),
                RawJson::encodeForStorage($this->shapedValue($request, 'value')),
                $request->validated('effective_at'),
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
                'effective_at' => $pair['effective_at'] ?? null,
            ];
        }

        return RawJson::response(
            RawJson::collection($this->store->createMany($pairs)),
            201,
        );
    }

    /** Live now, or as of ?timestamp=. A customer's view of time never exceeds now(). */
    public function show(Request $request, string $key): Response
    {
        $editor = $this->isEditor($request);

        $request->validate(
            [
                'timestamp' => ['nullable', 'regex:/^'.config('kv.epoch_pattern').'$/'],
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
            $envelope = $editor ? $this->store->liveAt($key, $timestamp) : $this->store->liveAtCapped($key, $timestamp);
        } else {
            $direct = filter_var($request->query('direct', 'false'), FILTER_VALIDATE_BOOL);

            $envelope = $direct ? $this->store->liveNow($key) : $this->store->latestCached($key);
        }

        if ($envelope === null) {
            $this->notFound($key, $timestamp, $editor);
        }

        return RawJson::response($envelope);
    }

    public function history(Request $request, string $key): Response
    {
        $editor = $this->isEditor($request);

        [$limit, $cursor] = $this->pagination($request, '/^'.config('kv.epoch_pattern').'$/');

        [$envelopes, $next] = $this->store->history($key, $limit, $cursor, $editor);

        // An empty first page means the key was never written. An empty later
        // page just means the caller walked off the end, which is not an error.
        if ($envelopes === [] && $cursor === null) {
            $this->notFound($key, null, $editor);
        }

        return RawJson::response(RawJson::page($envelopes, $next));
    }

    public function allKeys(Request $request): Response
    {
        $editor = $this->isEditor($request);

        $request->validate(['timestamp' => ['nullable', 'regex:/^'.config('kv.epoch_pattern').'$/']]);
        $explicitTimestamp = $request->query('timestamp');

        $cursorPattern = '/^'.config('kv.key_pattern').'\|'.config('kv.epoch_pattern').'$/';
        [$limit, $cursor] = $this->pagination($request, $cursorPattern);

        [$keyCursor, $cursorEpoch] = $cursor === null ? [null, null] : explode('|', $cursor, 2);

        // Precedence: an explicit ?timestamp= always wins for this page (and
        // is carried forward into its own next_cursor). Otherwise a walk in
        // progress reuses the instant its first page already resolved, so
        // every page of one walk stays consistent even if the caller never
        // repeats the parameter. A fresh walk with neither resolves "now"
        // once, by the database's own clock.
        $capEpoch = $explicitTimestamp ?? $cursorEpoch ?? $this->store->nowEpoch();

        [$envelopes, $next] = $this->store->allKeys($limit, $keyCursor, $capEpoch, $editor);

        return RawJson::response(RawJson::page($envelopes, $next));
    }

    /** Withdraw whatever is scheduled for $key at the given effective_at, before it ever goes live. */
    public function cancel(Request $request, string $key): Response
    {
        abort_if(! $this->isEditor($request), 401,
            'Cancelling a schedule requires a valid X-API-Key.');

        $request->validate(
            ['effective_at' => ['required', 'regex:/^'.config('kv.epoch_pattern').'$/']],
            ['effective_at.regex' => 'The effective_at ":input" is not a UNIX timestamp.'],
        );

        $epoch = $request->input('effective_at');

        abort_if(! $this->store->isFutureEpoch($epoch), 422,
            'That time has already passed. Only a still-future schedule may be cancelled.');

        abort_if(! $this->store->isScheduledAt($key, $epoch), 404,
            sprintf('Nothing is scheduled for key "%s" at that time.', $key));

        return RawJson::response($this->store->cancel($key, $epoch));
    }

    private function isEditor(Request $request): bool
    {
        return $request->attributes->get(ResolveApiClient::ATTRIBUTE) !== null;
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
     * never written (or never visible to this caller), against a key that
     * held nothing a caller of this kind could see at that time.
     */
    private function notFound(string $key, ?string $timestamp, bool $editor): never
    {
        throw new NotFoundHttpException(
            $timestamp !== null && $this->store->exists($key, $editor)
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
