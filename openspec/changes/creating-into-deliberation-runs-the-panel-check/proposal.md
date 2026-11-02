# Proposal: creating-into-deliberation-runs-the-panel-check

## Why

The change every-move-into-deliberation-runs-the-panel-check runs the panel check (Awb Art. 7:13 lid 3) when a save moves an advice request into `in-deliberation`. It listens to OpenRegister's `ObjectUpdatingEvent` only. A client can also create an advice request directly in `in-deliberation`, through the OpenRegister API or MCP. That create was not checked, so a conflicted or unverifiable panel could start deliberating that way.

## What changes

- The same listener also runs on OpenRegister's `ObjectCreatingEvent`.
- A new advice request whose status is `in-deliberation` gets the same panel check as a move into it.
- A refused check stops the create with the same reason and error code. OpenRegister saves nothing.
- A check that fails with an unexpected error stops the create too.
- A create in any other status is not checked.
- A save that keeps a request in `in-deliberation` but changes its panel or its objection runs the check again. The objection backfill, which copies `objectionProceeding` into `bezwaar`, does not count as a change.
- A request without an objection is unverifiable, not independent. `PanelIndependenceChecker` used to clear it.
- A save or create that reaches `advice-issued` or `inadmissible` from anywhere but `in-deliberation` is refused with error code `deliberation-skip-refused`. `transitionAdviceStatus()` already moves only from `in-deliberation`, so it keeps working.

## The refusal's trail entry

A refused create leaves no request to carry a trail entry. The entry is built exactly as for a refused move. Because the record does not resolve, the audit writer logs the full entry as an error, as REQ-BAT-003 requires for an entry that cannot be written. The create is refused either way.

## Known limit

Only creates that fire `ObjectCreatingEvent` are checked: the single-object create of the API, MCP and GraphQL. The bulk save, CSV and Excel import and flow writes fire no event, as for updates. Closing that gap belongs in OpenRegister.

## What does not change

- The panel check itself and its reasons.
- The check on a move into `in-deliberation`.
- `assignToCommittee()`, which creates advice requests in `assigned`.
