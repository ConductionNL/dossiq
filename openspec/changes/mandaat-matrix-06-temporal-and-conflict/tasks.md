# Tasks — Member 06: Temporal Queries + Conflict of Interest (code)

Sourced from giant tasks 12–13 (Temporal Mandate Queries; Belangenconflict Detection).

## 1. Temporal Queries

- [ ] Implement `getMandaatAsOf(mandaatId, date)` — version active on date, null before earliest / after latest
- [ ] Add optional `decisionDate` parameter to `isAuthorized()` (default today)
- [ ] Pass decisionDate to MandaatQueryService for temporal lookup
- [ ] Record the used mandaat version in MandaatGebruik (audit)
- [ ] Implement `suggestFutureDate(mandaatId, decisionProperties)` future-scheduling suggestion
- [ ] Test authorization with past and future dates; audit shows correct version

## 2. Conflict of Interest

- [ ] Implement `ConflictOfInterestService.checkConflict(userId, zaakId)` — extract applicant BSN, BRP relationship lookup, return {conflict, reason}
- [ ] Integrate conflict check into the isAuthorized pipeline → reden "belangenconflict" + escalation
- [ ] Implement manual conflict registration (case property + reason, block + escalate)
- [ ] Test automatic BRP detection; manual registration; decision blocked when conflict exists
