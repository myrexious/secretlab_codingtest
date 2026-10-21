# KV Notice Store

An append-only key-value store for customer-facing notice copy (e.g. `payment.card.transaction_notice`), extended to let editors schedule a value to take effect at a future UTC instant, and to cancel a scheduled value before it takes effect — without ever deleting a row.

## Language

**Recorded time** (`records.recorded_at`):
The instant the database assigned a row, via `clock_timestamp()`. Strictly increasing per key; breaks ties between two values that target the same effective time. This is ledger order, not business timing.
_Avoid_: timestamp (ambiguous with effective time), insertion time.

**Effective time** (`records.effective_at`):
The business instant a value is meant to be true from. Defaults to the row's own recorded time (immediate publish) when the caller omits it; set ahead of recorded time to schedule a value for the future.
_Avoid_: timestamp, go-live time (fine in conversation, not as a second name for the same column).

**Live value**:
For a key and an instant T, the value with the greatest effective time ≤ T, among values not cancelled for that key. This is what a customer fetching at T receives.

**Scheduled value**:
A value whose effective time is still in the future relative to "now." Visible to editors; invisible to customers, including through `?timestamp=` previews — a customer's view of time is always capped at `now()`, regardless of the timestamp they ask for.

**Superseded value**:
A value that can never be live again because a later-recorded value shares or precedes it at the relevant effective time and wins the tie. Kept for audit; never deleted; not specially flagged — it is simply outranked by the resolution rule.

**Cancelled slot**:
A `(key, effective_at)` pair withdrawn by an editor, recorded in `cancelled_schedules`. Every value row sharing that exact key and effective time is excluded from ever being live — past or future — once cancelled. Only a still-future slot (`effective_at > now()`) may be cancelled; a slot that has already gone live cannot be retracted through this mechanism.

**Editor** / **customer**:
Not stored anywhere — derived per request from whether `X-API-Key` resolved to an `ApiClient` (editor) or not (customer). An editor may preview a future effective time and sees scheduled/cancelled rows in `history`; a customer never does.
