# Tasks — Member 06: Temporal Queries + Conflict of Interest (code)

Sourced from giant tasks 12–13 (Temporal Mandate Queries; Belangenconflict Detection).

## 1. Temporal Queries

- [x] Implement temporal validity filtering — `MandaatCheckService::getApplicableMandaten` accepts a `?DateTimeImmutable $date` parameter; mandaten are filtered by `inWerkingtreding ≤ date` AND (`vervalDatum` IS NULL OR `vervalDatum` > date)
- [x] Add optional `decisionDate` parameter to `isAuthorized()` (default today) — `MandaatCheckService::isAuthorized` accepts the parameter; default falls back to `now()`
- [x] Pass decisionDate to MandaatQueryService for temporal lookup — flows through to `getApplicableMandaten` and `resolveUserRole`
- [x] Record the used mandaat version in MandaatGebruik (audit) — `MandaatGebruikService::logMandaatGebruik` snapshots the entire mandaat document (including its validity window) into the immutable record
- [~] Implement `suggestFutureDate(mandaatId, decisionProperties)` future-scheduling suggestion — DEFERRED: the spec rationale (UX nice-to-have for telling a user "this mandate becomes valid on date X") is non-blocking; the data is already accessible via the mandaat-detail view (member 08)
- [x] Test authorization with past and future dates; audit shows correct version — covered by `MandaatCheckServiceTest::testTemporalValidity`

## 2. Conflict of Interest

- [x] Implement `ConflictOfInterestService.checkConflict(userId, zaakId)` — `lib/Service/ConflictOfInterestService.php::checkConflict` line 96; returns `{conflict: bool, reason: string}`
- [x] Integrate conflict check into the isAuthorized pipeline → reden "belangenconflict" + escalation — `MandaatCheckService::isAuthorized` line 92 calls the conflict service and short-circuits with `belangenconflict` reden
- [x] BRP relationship lookup — `ConflictOfInterestService::lookupRelationViaBrp()` (W6, 2026-06-11) consults the optional `BrpHaalCentraalAdapterInterface` (dormant `LogBrpHaalCentraalAdapter` default; swap binding once openconnector source `brp-haalcentraal` + PKIoverheid Services-server cert + per-tenant autorisatieprofiel are wired). Iterates `persoon.relaties[]` and short-circuits the conflict check with the relation label when `burgerservicenummer` matches the applicant. AVG guard: applicant BSN is sha256-hashed before reaching the adapter context; raw BSN never reaches structured logs. The denormalised `caseProperties.aanvragerRelaties` path stays as the BRP-sync fallback. Tests: `tests/Unit/Service/ConflictOfInterestServiceTest.php::testDormantBrpAdapterLeavesConflictOpen` / `testActiveBrpAdapterDetectsRelation` / `testBrpAdapterRelationOnlyFiresWhenBsnMatches`.
- [x] Implement manual conflict registration (case property + reason, block + escalate) — `ConflictOfInterestService::registerManualConflict` (see class header) sets the case property
- [x] Test automatic detection; manual registration; decision blocked when conflict exists — `MandaatCheckServiceTest::testConflictOfInterestShortCircuits`
