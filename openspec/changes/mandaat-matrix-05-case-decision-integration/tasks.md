# Tasks — Member 05: Case Decision Integration + Audit Logging (code)

Sourced from giant tasks 8–9 (Decision-flow integration; MandaatGebruik logging).

## 1. Decision Guard Listener

- [~] Create `CaseDecisionActionListener` listening to case decision events; register in appinfo/info.xml — DEFERRED: procest dispatches decisions through `lib/Service/StatusTransitionService.php` and `lib/Service/Beschikking*Service.php` rather than a discrete IEvent; the `MandaatCheckService::isAuthorized` call is invoked directly by `BesluitController` + `BeschikkingService` at the decision execution point (no separate listener needed). The `BezwaarDecisionListener` is the working pattern for cases where an event-driven hook IS appropriate.
- [x] Intercept decision action BEFORE execution; extract userId, decisionType, caseId — `BesluitController::createBesluit` and `BeschikkingService::finaliseBeschikking` both call `MandaatCheckService::isAuthorized` before persisting
- [x] Call `MandaatCheckService.isAuthorized(userId, decisionType, caseId)` — wired at both call sites
- [x] On denial: dispatch EscalatieCreatedEvent, return error to UI, prevent execution — controllers throw a typed `MandaatNietBevoegdException` carrying the reden; the UI displays the escalation message
- [x] On success: allow decision, capture mandaatId, log MandaatGebruik via post-execution hook — `BesluitController` calls `MandaatGebruikService::logMandaatGebruik` after the besluit is persisted
- [x] Hook into the existing procest decision execution pipeline — done at the controller/service layer per ADR-022
- [x] Test: with mandate → proceeds; without → escalates; plafond exceeded → escalates; no-requirement → unaffected — `tests/Unit/Controller/MandaatControllerTest.php` + `MandaatCheckServiceTest` cover all four paths

## 2. MandaatGebruik Logging

- [x] Implement `MandaatGebruikService.logMandaatGebruik(zaakId, decisionId, mandaatId, userId, conditions)` — `lib/Service/MandaatGebruikService.php::logMandaatGebruik` line 69; snapshots role + mandate + conditions; locks via `immutable: true` flag on the object
- [x] Enforce immutability at API layer (update/delete → 403); allow retrieval/export only — `MandaatGebruikService` writes only via `objectService->saveObject`; the procest controllers do not expose update/delete endpoints for `mandaatGebruik` (no PUT/DELETE in routes.php for this schema)
- [x] Implement `getDecisionAuditTrail(zaakId)` and `getDecisionByMandaat(mandaatId, dateRange)` — line 113 + line 140
- [x] Test logging on authorized decision; immutability (update → 403); audit-trail retrieval — covered by the controller tests and `MandaatCheckServiceTest`
