# Design — Member 05: Case Decision Integration + Audit Logging (code)

## Scope

`CaseDecisionActionListener` + `MandaatGebruikService`. Consumes `MandaatCheckService` (member 02)
and `MandaatEscalatieService` (member 03); writes the `MandaatGebruik` schema (member 01) via
ObjectService (ADR-001).

## Listener contract

`CaseDecisionActionListener` listens to case decision events and intercepts BEFORE execution:
- extract `{userId, decisionType, caseId}`;
- call `MandaatCheckService.isAuthorized(userId, decisionType, caseId)`;
- if `{authorized: false}` → dispatch `EscalatieCreatedEvent` (member 03 creates the escalation),
  return an error response to the UI ("Not authorized — escalation offered"), prevent execution;
- if `{authorized: true}` → allow the decision to proceed, capture `mandaatId`, and after the
  decision completes create a MandaatGebruik log via a post-execution hook.

Registered in `appinfo/info.xml`. Decisions without a mandate requirement are unaffected.

## Audit-log contract

`MandaatGebruikService.logMandaatGebruik(zaakId, decisionId, mandaatId, userId, conditions)`:
- capture timestamp;
- resolve and snapshot the user's OrganisatieRol (`rolOpMomentVanBesluit`);
- snapshot mandate details and `gebruikteVoorwaarden`;
- create the MandaatGebruik record and lock it (`locked: true`).

Immutability: the MandaatGebruik schema is declared write-once in member 01; this member enforces
it at the API layer — update attempts return 403; only retrieval and export are permitted.

Retrieval: `getDecisionAuditTrail(zaakId)` → all entries for a zaak;
`getDecisionByMandaat(mandaatId, dateRange)` → all decisions using a mandate.

## Security (ADR-005, ADR-022)

The listener is the server-authoritative enforcement point — the UI's enabled/disabled state is a
hint, the listener is the gate. The snapshot is taken server-side from resolved records, never from
client input. Immutability is enforced server-side (403 on update), satisfying the audit-trail
abstraction (ADR-022). MandaatGebruik retrieval is scoped per zaak with the case's own access rules.
