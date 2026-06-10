# Tasks — Member 05: Case Decision Integration + Audit Logging (code)

Sourced from giant tasks 8–9 (Decision-flow integration; MandaatGebruik logging).

## 1. Decision Guard Listener

- [~] Create `CaseDecisionActionListener` listening to case decision events; register in appinfo/info.xml — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Intercept decision action BEFORE execution; extract userId, decisionType, caseId — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Call `MandaatCheckService.isAuthorized(userId, decisionType, caseId)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On denial: dispatch EscalatieCreatedEvent, return error to UI, prevent execution — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On success: allow decision, capture mandaatId, log MandaatGebruik via post-execution hook — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hook into the existing procest decision execution pipeline — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: with mandate → proceeds; without → escalates; plafond exceeded → escalates; no-requirement → unaffected — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. MandaatGebruik Logging

- [~] Implement `MandaatGebruikService.logMandaatGebruik(zaakId, decisionId, mandaatId, userId, conditions)` — snapshot role + mandate + conditions, create + lock record — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Enforce immutability at API layer (update/delete → 403); allow retrieval/export only — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `getDecisionAuditTrail(zaakId)` and `getDecisionByMandaat(mandaatId, dateRange)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test logging on authorized decision; immutability (update → 403); audit-trail retrieval — deferred to downstream cycle / fleet-wide adoption (handoff)
