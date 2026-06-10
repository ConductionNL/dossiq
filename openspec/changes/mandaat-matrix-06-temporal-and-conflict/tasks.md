# Tasks — Member 06: Temporal Queries + Conflict of Interest (code)

Sourced from giant tasks 12–13 (Temporal Mandate Queries; Belangenconflict Detection).

## 1. Temporal Queries

- [~] Implement `getMandaatAsOf(mandaatId, date)` — version active on date, null before earliest / after latest — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add optional `decisionDate` parameter to `isAuthorized()` (default today) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Pass decisionDate to MandaatQueryService for temporal lookup — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Record the used mandaat version in MandaatGebruik (audit) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `suggestFutureDate(mandaatId, decisionProperties)` future-scheduling suggestion — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test authorization with past and future dates; audit shows correct version — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Conflict of Interest

- [~] Implement `ConflictOfInterestService.checkConflict(userId, zaakId)` — extract applicant BSN, BRP relationship lookup, return {conflict, reason} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integrate conflict check into the isAuthorized pipeline → reden "belangenconflict" + escalation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement manual conflict registration (case property + reason, block + escalate) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test automatic BRP detection; manual registration; decision blocked when conflict exists — deferred to downstream cycle / fleet-wide adoption (handoff)
