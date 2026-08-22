---
status: done
---

# remaining-decision-delegation Specification

## Purpose
Delegates dossiq's remaining decision and advice flows — beslissing-op-bezwaar, BAC/advies/consultatie, and voorstel-besluit registration — to decidesk Decisions via the OpenRegister integration registry, so decidesk makes the decision while dossiq stops running local decision state machines. Delegation fails closed when decidesk is unavailable, the resulting ZGW Besluit is materialized as a projection of the decidesk outcome, and dossiq retains its Awb and IDOR domain validation, ZGW case management, and an idempotent repair step that links in-flight cases forward without data loss.
## Requirements
### Requirement: REQ-PDRD-001 — Remaining Decision/Advice Flows Are Raised As decidesk Decisions

dossiq SHALL raise a **decidesk `Decision`** via the OpenRegister integration registry (ADR-019)
for each of the remaining decision/advice flows, using the correct `decisionType` (ADR-005):
beslissing-op-bezwaar → `bezwaar-decision`; BAC-advies / adviesAanvraag / consultatie → `advice`;
voorstel→besluit → `report-adoption`. dossiq SHALL NOT advance a dossiq-local decision/advice
state machine to *make* the decision after this change.

#### Scenario: Beslissing-op-bezwaar backend raises a decidesk Decision

- **GIVEN** a bezwaar case and the `decidesk` integration leaf available
- **WHEN** `Bezwaar/DecisionService::draft()`/`publish()` is invoked with a valid Awb payload
- **THEN** dossiq SHALL raise a decidesk `Decision` with `decisionType` `bezwaar-decision` carrying the disposition, reasoning and legalBasis
- **AND** `Bezwaar/DecisionService` SHALL NOT author the besluit through a dossiq-local besluit engine
- **AND** the returned `decisionRef` SHALL be persisted on the case

#### Scenario: BAC-advies raises a decidesk advice Decision

- **GIVEN** a bezwaarschriftencommissie assigned to a bezwaar and the `decidesk` leaf available
- **WHEN** the committee advice is issued via `Bezwaar/AdvisoryCommitteeService`
- **THEN** dossiq SHALL raise a decidesk `Decision` with `decisionType` `advice` for the committee advice
- **AND** dossiq SHALL NOT author the advice outcome through a dossiq-local advice engine

#### Scenario: Advies/consultatie raises a decidesk advice Decision

- **GIVEN** an advice or consultation request and the `decidesk` leaf available
- **WHEN** `AdviceService::requestAdvice` or `ConsultationService::createConsultation` runs
- **THEN** dossiq SHALL raise a decidesk `Decision` with `decisionType` `advice`
- **AND** dossiq SHALL consume the advice outcome rather than authoring it locally

#### Scenario: Voorstel besluit-registration raises a decidesk Decision

- **GIVEN** a voorstel whose `canRegisterBesluit` gate is satisfied and the `decidesk` leaf available
- **WHEN** a user registers a besluit via `BesluitRegistration.vue`
- **THEN** dossiq SHALL raise a decidesk `Decision` with `decisionType` `report-adoption` for the voorstel
- **AND** dossiq SHALL NOT author the besluit through a dossiq-local path

---

### Requirement: REQ-PDRD-002 — Delegation Fails Closed When decidesk Is Unavailable

Every remaining-flow delegation SHALL **fail closed** when the `decidesk` integration leaf is
unavailable: it SHALL surface a clear "decision service unavailable" error and SHALL NOT auto-decide,
auto-advise, or fall back to a dossiq-local decision/advice authoring path. (Mirrors the
`unsafe-auth-resolver` rule — an unavailable decision service is not "decision skipped".)

#### Scenario: decidesk leaf unavailable blocks a beslissing-op-bezwaar

- **GIVEN** the `decidesk` integration leaf is not registered or returns an error
- **WHEN** dossiq attempts to raise a `bezwaar-decision` Decision
- **THEN** the call SHALL fail with a "decision service unavailable" error
- **AND** no `bezwaarDecision` SHALL be authored or published locally as a fallback

#### Scenario: decidesk leaf unavailable blocks an advice outcome

- **GIVEN** the `decidesk` integration leaf is unavailable
- **WHEN** dossiq attempts to raise an `advice` Decision for a BAC/advies/consultatie request
- **THEN** the call SHALL fail closed
- **AND** no dossiq-local advice outcome SHALL be set as a fallback

---

### Requirement: REQ-PDRD-003 — The ZGW Besluit/Advice Record Is A Projection Of The decidesk Outcome

dossiq SHALL materialise the ZGW `Besluit` (and the case advice record) **from the decidesk
Decision outcome**, not from a dossiq-local authoring path. The materialised `Besluit` SHALL
preserve the Besluiten-API shape: decidesk `result` → Besluit result, decidesk `decidedAt` →
`Besluit.datum`, decidesk motivering/advies → `Besluit.toelichting`, signer/method → audit fields,
and the rechtsmiddelenclausule SHALL be preserved on the materialised `Besluit`. ZGW compliance
SHALL NOT regress.

#### Scenario: A decided bezwaar Decision materialises a ZGW Besluit

- **GIVEN** a decidesk `bezwaar-decision` Decision reaches an outcome with disposition, datum and motivering
- **WHEN** dossiq consumes the outcome via `applyToBezwaar()` / `BesluitMaterialisationService`
- **THEN** dossiq SHALL write a ZGW `Besluit` on the case with the mapped result, the decided datum, the motivering as `toelichting`, and the rechtsmiddelenclausule
- **AND** the `Besluit` SHALL match the prior Besluiten-API schema shape (verified by a contract test)

#### Scenario: A decided advice Decision updates the advice record

- **GIVEN** a decidesk `advice` Decision for an adviesAanvraag/consultatie reaches an outcome
- **WHEN** dossiq consumes the outcome
- **THEN** dossiq SHALL reflect the advice result on the case advice record (status, adviesText) as a projection
- **AND** dossiq SHALL NOT author the advice result through a dossiq-local advice engine

---

### Requirement: REQ-PDRD-004 — The Awb And IDOR Domain Rules Stay In dossiq

dossiq SHALL retain its decision/advice **domain rules** as dossiq validation that runs BEFORE a
Decision is raised: the Awb art. 7:11 disposition set, art. 7:12 reasoning+legalBasis requirement,
proceskosten rules, the replacement-decision guard, the BAC panel-independence check, the advice
IDOR fail-closed authorisation (only the assigned adviseur/admin may submit), and the
rechtsmiddelenclausule completeness check. decidesk decides; dossiq validates the legal shape.

#### Scenario: An Awb-invalid bezwaar payload is rejected before any Decision is raised

- **GIVEN** a beslissing-op-bezwaar payload missing the art. 7:12 reasoning/legalBasis or with an invalid 7:11 disposition
- **WHEN** `Bezwaar/DecisionService` processes it
- **THEN** dossiq SHALL reject the payload with a validation error
- **AND** no decidesk Decision SHALL be raised for the invalid payload

#### Scenario: Advice IDOR gate still applies

- **GIVEN** a user who is not the assigned adviseur (and not admin) submitting advice on an adviesAanvraag
- **WHEN** `AdviceService::submitAdvice` is invoked
- **THEN** dossiq SHALL reject the request (IDOR fail-closed, unchanged by this delegation)

#### Scenario: BAC panel-independence still guards deliberation

- **GIVEN** a BAC advice request transitioning `assigned → in-deliberation` with a panel that fails independence
- **WHEN** `Bezwaar/AdvisoryCommitteeService::transitionAdviceStatus` runs
- **THEN** dossiq SHALL block the transition and record the independence failure (REQ-BAC-2 retained)

---

### Requirement: REQ-PDRD-005 — dossiq Keeps ZGW Case Management; Nav And Parafering Are Untouched

dossiq SHALL keep ZGW case management unchanged: the bezwaar/voorstel/advies remains a zaak and the
ZGW `Besluit` is still recorded on the case dossier (now as a projection). This change SHALL NOT
modify the navigation/menu layout (owned by the sibling change `dossiq-objections-appeals-group`)
and SHALL NOT modify the parafeerroute/parafering approval chain (owned by
`migrate-parafering-to-or-approval-workflow`). Only the decision/advice *making* is delegated.

#### Scenario: No nav or menu-layout edit is shipped

- **GIVEN** this change is applied
- **WHEN** the diff is inspected
- **THEN** no `src/menu-layout.json` or nav/manifest grouping entry SHALL be added, moved or removed by this change

#### Scenario: The voorstel parafeerroute is left intact

- **GIVEN** a voorstel with an active parafeerroute
- **WHEN** this change is applied
- **THEN** the parafeerroute components and endpoints SHALL be unchanged
- **AND** only the besluit-registration node on the voorstel SHALL delegate to decidesk

---

### Requirement: REQ-PDRD-006 — In-Flight Remaining-Decision Cases Are Migrated Without Data Loss

dossiq SHALL provide a `lib/Repair/*` step that links in-flight bezwaar-decision / advies /
consultatie / voorstel cases forward to a decidesk `Decision` so their outcome can complete in
decidesk. Cases that already have a recorded `Besluit`/advice SHALL keep that record as the
authoritative historical record. The step SHALL be idempotent and fail-safe; no decision/advice
data SHALL be dropped by the migration.

#### Scenario: Open undecided case is linked to a decidesk Decision

- **GIVEN** an open bezwaar/advies/voorstel case with no decision recorded yet
- **WHEN** the repair step runs
- **THEN** the case SHALL be linked to a decidesk `Decision` of the appropriate `decisionType` so its outcome can complete in decidesk

#### Scenario: Already-decided case keeps its historical record

- **GIVEN** a closed case that already has a recorded ZGW `Besluit` or advice record
- **WHEN** the repair step runs (including a re-run)
- **THEN** the existing record SHALL be retained as the authoritative historical record
- **AND** no decision/advice data SHALL be dropped or overwritten

