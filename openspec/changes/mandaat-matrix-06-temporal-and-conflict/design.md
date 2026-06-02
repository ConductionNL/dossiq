# Design — Member 06: Temporal Queries + Conflict of Interest (code)

## Scope

`MandaatQueryService` (effective dating) + `ConflictOfInterestService` (belangenconflict). Both
extend `MandaatCheckService.isAuthorized()` (member 02) and the MandaatGebruik snapshot (member 05).

## Temporal contract

`MandaatQueryService.getMandaatAsOf(mandaatId, date)` — query Mandaat with that mandaatNummer where
`geldigVanaf ≤ date ≤ geldigTotEnMet`; return the matching version (null if before earliest /
after latest). `isAuthorized()` gains an optional `decisionDate` (default today) passed through to
`getMandaatAsOf` so authorization uses the version effective on the decision date. The chosen
version is recorded in the MandaatGebruik snapshot for audit (immutable — never re-evaluated against
the current version). `suggestFutureDate(mandaatId, decisionProperties)` returns a scheduling hint
(e.g. "Schedule for 2026-07-01 to use newer mandate with plafond €100K") when a future version would
authorize a currently-escalating decision.

## Conflict contract

`ConflictOfInterestService.checkConflict(userId, zaakId)`:
- extract applicant BSN from the case;
- call the BRP service: is the user related to the applicant (spouse, child, parent, sibling)?
- return `{conflict: bool, reason: string}`.

Integrated into the `isAuthorized()` pipeline: a detected conflict yields
`{authorized: false, reden: "belangenconflict"}` and triggers an escalation (member 03) to a
different role holder. Manual registration sets a case property (`potentiaalConflict` / reason) and
likewise blocks the decision + escalates.

## Security (ADR-005)

BRP lookups are server-side; BSN is never exposed to the client. The temporal version is resolved
server-side from validity windows, not client-supplied dates beyond the requested decisionDate which
is itself validated. Conflict reasons are stored on the audit trail.
