# Tasks — Member 05: Case Decision Integration + Audit Logging (code)

Sourced from giant tasks 8–9 (Decision-flow integration; MandaatGebruik logging).

## 1. Decision Guard Listener

- [ ] Create `CaseDecisionActionListener` listening to case decision events; register in appinfo/info.xml
- [ ] Intercept decision action BEFORE execution; extract userId, decisionType, caseId
- [ ] Call `MandaatCheckService.isAuthorized(userId, decisionType, caseId)`
- [ ] On denial: dispatch EscalatieCreatedEvent, return error to UI, prevent execution
- [ ] On success: allow decision, capture mandaatId, log MandaatGebruik via post-execution hook
- [ ] Hook into the existing procest decision execution pipeline
- [ ] Test: with mandate → proceeds; without → escalates; plafond exceeded → escalates; no-requirement → unaffected

## 2. MandaatGebruik Logging

- [ ] Implement `MandaatGebruikService.logMandaatGebruik(zaakId, decisionId, mandaatId, userId, conditions)` — snapshot role + mandate + conditions, create + lock record
- [ ] Enforce immutability at API layer (update/delete → 403); allow retrieval/export only
- [ ] Implement `getDecisionAuditTrail(zaakId)` and `getDecisionByMandaat(mandaatId, dateRange)`
- [ ] Test logging on authorized decision; immutability (update → 403); audit-trail retrieval
