## Context
The store now serves customer-facing fintech copy: payment.card.transaction_notice, transfer.bank_maintenance_banner, country.my.funding_message. Today a saved value is visible immediately. Operations wants to prepare notices ahead of time and have them go live at a chosen UTC moment, **with no background worker or cron**.

## Business scenarios (the requirements live here)
Key payment.card.transaction_notice, all times UTC, same day:

| Time | Event |
|---|---|
| 10:00 | Editor saves "Card payments are operating normally." (immediate) |
| 11:00 | Editor schedules "Scheduled maintenance begins at 18:00 UTC." to go live at 18:00 |
| 11:30 | Editor schedules "Maintenance is under way." for 18:00 too (copy was revised) |
| 12:00 | Editor schedules "Payments are operating normally." for 21:00 |
| 15:00 | Compliance asks for a wording fix; editor saves an immediate "Card payments are operating normally. Fees may apply." |
| 16:30 | Maintenance is **cancelled**. Customers must **never** see any maintenance wording. Nothing may be deleted (audit). |

Expected customer view: 12:00 sees the 10:00 text; 15:01 sees the fix; 18:00 onward must see the **post-cancellation** result; 21:00 onward is normal service.

Further requirements:
1. Customers must see a scheduled value **within 1 second** of its go-live time, with no one pressing anything.
2. Existing clients that send {"key","value"} and read {"key","value","timestamp"} must keep working unchanged.
3. Existing ?timestamp= reads must still mean "what was live at that time."
4. Editors need to see what is scheduled for a key and what was cancelled; customers must not.
5. get-all-keys must show each key's value as live **now** (or at a supplied time), paginated, stable while someone paginates.
6. Bulk create must be able to schedule too, and stay atomic.
7. No new dependencies.

## Before coding (about 15 min)
Ask clarifying questions, list risks you see in the existing implementation, and state the API contract, data-model change, assumptions, trade-offs, and test strategy. Then implement the agreed slice. AI/search allowed; at the end say what AI contributed and how you verified it.

## Deliverables
API examples, migration/backward-compat notes, tests added, known limitations and next steps.



## Planned implementation
Change Req
1. Adjust so Create can include time as well. Field is optional. On same key re-insert it would be replacing the old value.
2. Adjust bulk create to have same field as create for the timestamp. On same key re-insert it would be replacing the old value.
..
5. Add changelog: [{ user: <user/auth key>, changes: <changes>}]


DB adjustment:
- remove the auto insert for the timestamp field
- add changelog field