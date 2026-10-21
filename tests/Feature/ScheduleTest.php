<?php

use App\Models\ApiClient;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function editorKey(): string
{
    $plainKey = ApiClient::generateKey();

    ApiClient::create([
        'name' => 'editor-'.$plainKey,
        'key_hash' => ApiClient::hash($plainKey),
        'rate_limit_per_minute' => 1000,
    ]);

    return $plainKey;
}

/** A decimal epoch $seconds from now, by PHP's clock -- only ever used as a request payload, never compared against. */
function inSeconds(float $seconds): string
{
    return (string) (microtime(true) + $seconds);
}

describe('scheduling a value', function () {
    it('does not show a scheduled value before its effective time', function () {
        postJson('/kv-value/data', ['key' => 'sched', 'value' => 'now'])->assertCreated();
        postJson('/kv-value/data', ['key' => 'sched', 'value' => 'later', 'effective_at' => inSeconds(30)])
            ->assertCreated();

        getJson('/kv-value/data/sched')->assertOk()->assertJson(['value' => 'now']);
    });

    it('goes live on its own within about a second of its effective time -- no request triggers it', function () {
        postJson('/kv-value/data', ['key' => 'tight', 'value' => 'before'])->assertCreated();
        postJson('/kv-value/data', ['key' => 'tight', 'value' => 'after', 'effective_at' => inSeconds(0.4)])
            ->assertCreated();

        getJson('/kv-value/data/tight')->assertJson(['value' => 'before']);

        usleep(900_000);

        getJson('/kv-value/data/tight')->assertJson(['value' => 'after']);
    });

    it('lets a later edit supersede an earlier one scheduled for the same instant', function () {
        $at = inSeconds(2);
        postJson('/kv-value/data', ['key' => 'dup', 'value' => 'first wording', 'effective_at' => $at])
            ->assertCreated();
        postJson('/kv-value/data', ['key' => 'dup', 'value' => 'revised wording', 'effective_at' => $at])
            ->assertCreated();

        usleep(2_300_000);

        getJson('/kv-value/data/dup')->assertJson(['value' => 'revised wording']);
    });

    it('rejects a backdated effective_at', function () {
        postJson('/kv-value/data', ['key' => 'late', 'value' => 1, 'effective_at' => (string) (time() - 10)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_at');
    });

    it('rejects a malformed effective_at', function () {
        postJson('/kv-value/data', ['key' => 'bad', 'value' => 1, 'effective_at' => 'tomorrow'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_at');
    });

    it('schedules inside bulk create too, and a bad pair still rejects the whole batch', function () {
        postJson('/kv-value/bulk-create', ['pairs' => [
            ['key' => 'bulk.one', 'value' => 1, 'effective_at' => inSeconds(30)],
            ['key' => 'bulk.two', 'value' => 2, 'effective_at' => 'not a number'],
        ]])->assertStatus(422);

        expect(DB::table('records')->count())->toBe(0);
    });
});

describe('an anonymous customer never sees scheduled or cancelled content', function () {
    it('caps ?timestamp= at now, even when a future time is requested', function () {
        postJson('/kv-value/data', ['key' => 'peek', 'value' => 'now'])->assertCreated();
        $future = inSeconds(30);
        postJson('/kv-value/data', ['key' => 'peek', 'value' => 'future', 'effective_at' => $future])
            ->assertCreated();

        getJson('/kv-value/data/peek?timestamp='.$future)->assertJson(['value' => 'now']);
    });

    it('omits a still-pending schedule from history', function () {
        postJson('/kv-value/data', ['key' => 'hide', 'value' => 'now'])->assertCreated();
        postJson('/kv-value/data', ['key' => 'hide', 'value' => 'future', 'effective_at' => inSeconds(30)])
            ->assertCreated();

        $values = collect(getJson('/kv-value/history/hide')->json('data'))->pluck('value');

        expect($values->all())->toBe(['now']);
    });
});

describe('an authenticated editor', function () {
    it('may preview a future effective time through ?timestamp=', function () {
        $key = editorKey();
        postJson('/kv-value/data', ['key' => 'preview', 'value' => 'now'])->assertCreated();
        $future = inSeconds(30);
        postJson('/kv-value/data', ['key' => 'preview', 'value' => 'future', 'effective_at' => $future])
            ->assertCreated();

        getJson('/kv-value/data/preview?timestamp='.$future, ['X-API-Key' => $key])
            ->assertOk()->assertJson(['value' => 'future']);
    });

    it('sees a pending schedule in history, tagged not cancelled', function () {
        $key = editorKey();
        postJson('/kv-value/data', ['key' => 'audit', 'value' => 'now'])->assertCreated();
        postJson('/kv-value/data', ['key' => 'audit', 'value' => 'future', 'effective_at' => inSeconds(30)])
            ->assertCreated();

        $data = collect(getJson('/kv-value/history/audit', ['X-API-Key' => $key])->json('data'));

        expect($data->pluck('value')->all())->toBe(['future', 'now'])
            ->and($data->pluck('cancelled')->all())->toBe([false, false]);
    });
});

describe('cancel', function () {
    it('requires a valid X-API-Key', function () {
        postJson('/kv-value/data', ['key' => 'c1', 'value' => 1, 'effective_at' => inSeconds(30)])->assertCreated();

        postJson('/kv-value/data/c1/cancel', ['effective_at' => inSeconds(30)])->assertStatus(401);
    });

    it('rejects cancelling a time that has already passed', function () {
        postJson('/kv-value/data/c1/cancel', ['effective_at' => (string) (time() - 10)], ['X-API-Key' => editorKey()])
            ->assertStatus(422);
    });

    it('answers 404 when nothing is scheduled at that time', function () {
        postJson('/kv-value/data/c1/cancel', ['effective_at' => inSeconds(30)], ['X-API-Key' => editorKey()])
            ->assertStatus(404);
    });

    it('withdraws a schedule so it never goes live, falling back to what was already live', function () {
        $key = editorKey();
        $at = inSeconds(2);

        postJson('/kv-value/data', ['key' => 'cancel-me', 'value' => 'normal'])->assertCreated();
        postJson('/kv-value/data', ['key' => 'cancel-me', 'value' => 'maintenance', 'effective_at' => $at])
            ->assertCreated();

        postJson('/kv-value/data/cancel-me/cancel', ['effective_at' => $at], ['X-API-Key' => $key])->assertOk();

        usleep(2_300_000);

        getJson('/kv-value/data/cancel-me')->assertJson(['value' => 'normal']);
    });

    it('is idempotent: cancelling the same slot twice reports the original cancelled_at', function () {
        $key = editorKey();
        $at = inSeconds(30);

        postJson('/kv-value/data', ['key' => 'c2', 'value' => 1, 'effective_at' => $at])->assertCreated();

        $first = postJson('/kv-value/data/c2/cancel', ['effective_at' => $at], ['X-API-Key' => $key])->assertOk();
        $second = postJson('/kv-value/data/c2/cancel', ['effective_at' => $at], ['X-API-Key' => $key])->assertOk();

        expect($second->json('cancelled_at'))->toBe($first->json('cancelled_at'));
    });

    it('silences every row sharing the cancelled slot, not just the one currently winning it', function () {
        $key = editorKey();
        $at = inSeconds(2);

        postJson('/kv-value/data', ['key' => 'both', 'value' => 'normal'])->assertCreated();
        postJson('/kv-value/data', ['key' => 'both', 'value' => 'maintenance v1', 'effective_at' => $at])
            ->assertCreated();
        postJson('/kv-value/data', ['key' => 'both', 'value' => 'maintenance v2', 'effective_at' => $at])
            ->assertCreated();

        postJson('/kv-value/data/both/cancel', ['effective_at' => $at], ['X-API-Key' => $key])->assertOk();

        usleep(2_300_000);

        getJson('/kv-value/data/both')->assertJson(['value' => 'normal']);
    });
});

describe("the cache's TTL shrinks to land on a pending go-live, instead of serving stale data past it", function () {
    it('still reflects the new value shortly after go-live, despite having cached the old one', function () {
        postJson('/kv-value/data', ['key' => 'cache-cap', 'value' => 'before'])->assertCreated();
        postJson('/kv-value/data', ['key' => 'cache-cap', 'value' => 'after', 'effective_at' => inSeconds(2)])
            ->assertCreated();

        // Populates the cache with "before". A flat 60s TTL would keep
        // serving this well past go-live; the capped TTL must not.
        getJson('/kv-value/data/cache-cap')->assertJson(['value' => 'before']);

        usleep(2_300_000);

        getJson('/kv-value/data/cache-cap')->assertJson(['value' => 'after']);
    });
});

describe('get-all-keys', function () {
    it('accepts ?timestamp= and resolves each key as of that instant', function () {
        postJson('/kv-value/data', ['key' => 'gak', 'value' => 'old'])->assertCreated();
        $future = inSeconds(30);
        postJson('/kv-value/data', ['key' => 'gak', 'value' => 'future', 'effective_at' => $future])
            ->assertCreated();

        $now = collect(getJson('/kv-value/get-all-keys')->json('data'));
        expect($now->firstWhere('key', 'gak')['value'])->toBe('old');

        $preview = collect(
            getJson('/kv-value/get-all-keys?timestamp='.$future, ['X-API-Key' => editorKey()])->json('data'),
        );
        expect($preview->firstWhere('key', 'gak')['value'])->toBe('future');
    });

    it('keeps a multi-page walk stable even when a schedule goes live between pages', function () {
        $at = inSeconds(2);
        postJson('/kv-value/data', ['key' => 'walk.a', 'value' => 'old-a'])->assertCreated();
        postJson('/kv-value/data', ['key' => 'walk.a', 'value' => 'new-a', 'effective_at' => $at])->assertCreated();
        postJson('/kv-value/data', ['key' => 'walk.b', 'value' => 'b'])->assertCreated();

        $page1 = getJson('/kv-value/get-all-keys?limit=1')->assertOk();
        expect($page1->json('data.0.key'))->toBe('walk.a')
            ->and($page1->json('data.0.value'))->toBe('old-a');

        // Real time passes the schedule's go-live instant between pages.
        usleep(2_300_000);

        // Page 2 of THIS walk still resolves against page 1's snapshot instant.
        $page2 = getJson('/kv-value/get-all-keys?limit=1&cursor='.$page1->json('next_cursor'))->assertOk();
        expect($page2->json('data.0.key'))->toBe('walk.b');

        // A fresh walk (no cursor) sees the change.
        $fresh = getJson('/kv-value/get-all-keys?limit=1')->assertOk();
        expect($fresh->json('data.0.value'))->toBe('new-a');
    });
});

describe('the full business scenario from plans/tmp.md, compressed into seconds', function () {
    it('matches every expected customer view across the walk', function () {
        $key = editorKey();
        $at = fn (float $offset) => inSeconds($offset);

        // 10:00 -- immediate
        postJson('/kv-value/data', ['key' => 'notice', 'value' => 'Card payments are operating normally.'])
            ->assertCreated();

        // 11:00 -- schedules maintenance for "18:00" (+3s)
        $eighteen = $at(3);
        postJson('/kv-value/data', [
            'key' => 'notice', 'value' => 'Scheduled maintenance begins at 18:00 UTC.', 'effective_at' => $eighteen,
        ])->assertCreated();

        // 11:30 -- revises the same slot
        postJson('/kv-value/data', [
            'key' => 'notice', 'value' => 'Maintenance is under way.', 'effective_at' => $eighteen,
        ])->assertCreated();

        // 12:00 -- schedules "21:00" (+6s)
        $twentyOne = $at(6);
        postJson('/kv-value/data', [
            'key' => 'notice', 'value' => 'Payments are operating normally.', 'effective_at' => $twentyOne,
        ])->assertCreated();

        // 12:00 view: still the 10:00 text
        getJson('/kv-value/data/notice')->assertJson(['value' => 'Card payments are operating normally.']);

        // 15:00 -- compliance fix, immediate
        postJson('/kv-value/data', [
            'key' => 'notice', 'value' => 'Card payments are operating normally. Fees may apply.',
        ])->assertCreated();

        getJson('/kv-value/data/notice')
            ->assertJson(['value' => 'Card payments are operating normally. Fees may apply.']);

        // 16:30 -- cancels the whole "18:00" slot
        postJson('/kv-value/data/notice/cancel', ['effective_at' => $eighteen], ['X-API-Key' => $key])->assertOk();

        // Customers never saw either maintenance wording, scheduled or cancelled
        $customerValues = collect(getJson('/kv-value/history/notice')->json('data'))->pluck('value');
        expect($customerValues)->not->toContain('Scheduled maintenance begins at 18:00 UTC.')
            ->and($customerValues)->not->toContain('Maintenance is under way.');

        // Past "18:00": the cancellation held -- the fix is still live
        usleep(3_300_000);
        getJson('/kv-value/data/notice')
            ->assertJson(['value' => 'Card payments are operating normally. Fees may apply.']);

        // Past "21:00": the final schedule has taken over
        usleep(3_300_000);
        getJson('/kv-value/data/notice')->assertJson(['value' => 'Payments are operating normally.']);

        // The editor's audit trail shows everything, including what was cancelled
        $editorHistory = collect(getJson('/kv-value/history/notice', ['X-API-Key' => $key])->json('data'));
        expect($editorHistory->pluck('value'))
            ->toContain('Scheduled maintenance begins at 18:00 UTC.', 'Maintenance is under way.');
        expect($editorHistory->where('value', 'Maintenance is under way.')->first()['cancelled'])->toBeTrue();
    });
});
