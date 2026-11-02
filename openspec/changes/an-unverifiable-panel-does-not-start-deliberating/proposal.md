# Proposal: an-unverifiable-panel-does-not-start-deliberating

## Why

Under Awb Art. 7:13 lid 3, no member of a bezwaaradviescommissie panel may have worked on the contested primair besluit. The archived change `2026-05-11-bezwaar-advisory-committee` says a match blocks the move from `assigned` to `in-deliberation`. It does not say what happens when the check cannot run. The code then cleared the panel: a store read that threw, or a missing objection or decision schema, let the request start deliberating with only a log line.

## What changes

- When independence cannot be verified, the advice request stays in `assigned`.
- The refusal names no member and gives the reason "Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)".
- The request's trail gets an `independence-check-unverifiable` entry, written the same way as `independence-check-failed`.
- A chain that resolves no person on the contested decision is refused the same way. So is an advice request that names no objection, and a missing object store.
