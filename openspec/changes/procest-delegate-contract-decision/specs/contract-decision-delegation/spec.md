# contract-decision-delegation Specification

**Status:** proposed
**Scope:** procest
**Tier:** V1
**Depends on:** `decidesk-contract-decision-hub` (decidesk side — exposes the contract-decision
integration surface), ADR-019 integration registry (OpenRegister side).

## Purpose

Define the procest-side contract for delegating contract / besluit **decisions** (approve, renew,
sign-off, beslissing-op-bezwaar) to **decidesk** via the ADR-019 integration registry, while procest
keeps ZGW **case management** and records the ZGW `Besluit` artifact on the case file from the
decidesk outcome. decidesk owns the *making* of the decision; procest records the ZGW `Besluit` for
the zaak dossier (Besluiten API). This removes procest's parallel approval / besluitvorming engine
(ADR-012 dedup, ADR-022 consume-don't-duplicate) without breaking ZGW compliance.

## ADDED Requirements

### Requirement: REQ-PDCD-001 — Contract Decisions Are Raised As decidesk Decisions

procest SHALL raise a **decidesk `Decision`** for any contract approval / renewal / sign-off via the
OpenRegister integration registry (ADR-019), through a new `ContractDecisionDelegationService`.
procest SHALL NOT advance a procest-local approval state machine for the decision after this change.

#### Scenario: Renewal request raises a decidesk Decision

- **GIVEN** a supplier contract within the 90-day renewal window and the `decidesk` integration leaf available
- **WHEN** a contracts/admin user requests renewal via `ContractController::requestRenewal`
- **THEN** procest SHALL still open the `leverancier-contractverlenging-verzoek` ZGW case
- **AND** procest SHALL call `ContractDecisionDelegationService::raiseContractDecision(...)`, creating a decidesk `Decision`
- **AND** the returned `decisionRef` SHALL be persisted on the case
- **AND** no procest-local approval state machine SHALL advance the decision

#### Scenario: Beslissing-op-bezwaar raises a decidesk Decision

- **GIVEN** a bezwaar case and the `decidesk` integration leaf available
- **WHEN** a behandelaar submits `BezwaarDecisionForm.vue`
- **THEN** procest SHALL raise a decidesk `Decision` carrying the disposition, motivation and follows-advice values
- **AND** procest SHALL NOT author the besluit through a procest-local besluit engine

---

### Requirement: REQ-PDCD-002 — Delegation Fails Closed When decidesk Is Unavailable

The `ContractDecisionDelegationService` SHALL **fail closed** when the `decidesk` integration leaf is
unavailable: it SHALL surface a clear "decision service unavailable" error and SHALL NOT auto-approve
or fall back to a procest-local approval. (Mirrors the `unsafe-auth-resolver` rule — an unavailable
decision service is not "decision skipped".)

#### Scenario: decidesk leaf unavailable blocks the decision

- **GIVEN** the `decidesk` integration leaf is not registered or returns an error
- **WHEN** procest attempts to raise a contract decision
- **THEN** the call SHALL fail with a "decision service unavailable" error
- **AND** no contract SHALL be marked approved/renewed
- **AND** no procest-local approval state SHALL be set as a fallback

---

### Requirement: REQ-PDCD-003 — The ZGW Besluit Is Materialised From The decidesk Outcome

procest SHALL materialise the ZGW `Besluit` on the case **from the decidesk Decision outcome**, not
from a procest-local besluit-authoring path. The materialised `Besluit` SHALL preserve the Besluiten-API
shape: decidesk `result` → Besluit result, decidesk `decidedAt` → `Besluit.datum`, decidesk
motivering/advice → `Besluit.toelichting`, decidesk signer/mandaathouder + method → recorded audit
fields. ZGW compliance SHALL NOT regress.

#### Scenario: Approved decidesk Decision materialises a ZGW Besluit

- **GIVEN** a decidesk `Decision` for a contract reaches outcome `verleend` with a datum, motivering and mandaathouder
- **WHEN** procest consumes the outcome via `ContractDecisionDelegationService::consumeOutcome(...)`
- **THEN** procest SHALL write a ZGW `Besluit` on the case with result `verleend`, the decided datum, and the motivering as `toelichting`
- **AND** the `Besluit` SHALL match the prior Besluiten-API schema shape (verified by a contract test)

#### Scenario: Rejected decidesk Decision is recorded on the case file

- **GIVEN** a decidesk `Decision` reaches outcome `geweigerd`
- **WHEN** procest consumes the outcome
- **THEN** procest SHALL record a `Besluit` with result `geweigerd` and the motivering on the case dossier

---

### Requirement: REQ-PDCD-004 — procest Keeps ZGW Case Management And The Expiry Scan

procest SHALL keep ZGW case management unchanged: the contract remains a zaak; the nightly
`ScanExpiringContractsJob` SHALL still run and flag `renewalWarning` on contracts entering the 90-day
window; the supplier portal (`ContractController::index`/`show`) SHALL still list/serve contracts with
the IDOR fail-closed supplier scoping preserved. Only the *decision* node is delegated.

#### Scenario: Nightly expiry scan still flags contracts

- **GIVEN** a supplier contract whose `endDate` falls within 90 days and `renewalWarning` is unset
- **WHEN** `ScanExpiringContractsJob` runs
- **THEN** the contract SHALL have `renewalWarning` set to true
- **AND** a second run in the same window SHALL write nothing (idempotent)

#### Scenario: Supplier portal still scopes contracts fail-closed

- **GIVEN** supplier A requests `ContractController::show` for a contract owned by supplier B
- **WHEN** the request is processed
- **THEN** procest SHALL return 403 (IDOR fail-closed scope preserved, unchanged by this delegation)

---

### Requirement: REQ-PDCD-005 — The Besluitvorming Engine Is Narrowed To ZGW-Record

procest SHALL narrow its besluitvorming endpoints to ZGW-record / case-orchestration roles and SHALL
stop authoring decisions locally. `PublicationController::publish` SHALL publish the recorded ZGW
`Besluit` (fed by the decidesk outcome) rather than author it; `BesluitvormingController::activateTemplate`
and the `bvw-*` decision-type templates SHALL be deprecated in favour of decidesk decisionTypes;
`AgendaController` agenda endpoints SHALL remain as ZGW case orchestration. Endpoints that remain
routable for deep-link/back-compat after their decision role is removed SHALL stay registered.

#### Scenario: Publish operates on the recorded Besluit, not a local authoring path

- **GIVEN** a case whose `Besluit` was materialised from a decidesk outcome
- **WHEN** `PublicationController::publish` is called
- **THEN** procest SHALL publish the already-recorded ZGW `Besluit` on the requested channel
- **AND** procest SHALL NOT author a new besluit through a procest-local besluit engine

#### Scenario: bvw decision-type templates are deprecated

- **GIVEN** the `bvw-mandaatbesluit`, `bvw-college-besluit` and `bvw-raadsbesluit` templates
- **WHEN** this change ships
- **THEN** the templates SHALL be marked deprecated and decision *types* SHALL come from decidesk decisionTypes
- **AND** existing template rows SHALL remain readable until sunset

---

### Requirement: REQ-PDCD-006 — Mandate Checking Delegates To The decidesk Decision Route

procest SHALL delegate "is the signing user mandated?" to the decidesk decision route/stage assignee
model. `MandaatController::mandaatCheck` SHALL be reduced to a thin read-through of the decidesk
mandate answer (or removed once callers migrate); procest SHALL NOT maintain a parallel mandate
authority for contract/besluit decisions.

#### Scenario: Mandate answer comes from decidesk

- **GIVEN** a contract/besluit decision raised in decidesk with a route stage assigned to a mandated role
- **WHEN** procest checks whether a signing user is mandated
- **THEN** procest SHALL obtain the mandate answer from the decidesk decision route/stage assignee model
- **AND** procest SHALL NOT compute the mandate from a procest-local mandate engine for that decision

---

### Requirement: REQ-PDCD-007 — In-Flight Contract Cases Are Migrated Without Data Loss

procest SHALL provide a `lib/Repair/*` step that links in-flight contract / besluitvorming cases
forward to a decidesk `Decision` so their outcome can complete in decidesk. Cases that already have a
recorded `Besluit` SHALL keep that `Besluit` as the authoritative historical record. No `Besluit` data
SHALL be dropped by the migration.

#### Scenario: Open case is linked to a decidesk Decision

- **GIVEN** an open contract case with no decision recorded yet
- **WHEN** the repair step runs
- **THEN** the case SHALL be linked to a decidesk `Decision` so its outcome can complete in decidesk

#### Scenario: Already-decided case keeps its historical Besluit

- **GIVEN** a closed case that already has a recorded ZGW `Besluit`
- **WHEN** the repair step runs
- **THEN** the existing `Besluit` SHALL be retained as the authoritative historical record
- **AND** no `Besluit` data SHALL be dropped or overwritten

## REMOVED Requirements

### Requirement: REQ-PDCD-008 — Remove The procest-Local Contract/Besluit Approval Authority

procest SHALL remove its parallel contract/besluit **decision-making** authority: the renewal-approval
state path, the besluit-authoring path in `BezwaarDecisionForm.vue` / `PublicationService`, the
procest-local decision-type persistence in `DecisionTypesTab.vue`, and the procest-local mandate
authority for these decisions. The case/zaak engine, the expiry scan, the supplier portal, and the
ZGW `Besluit` *recording* are NOT removed (they are genuine ZGW case management).

#### Scenario: No procest-local approval advances a contract decision

- **GIVEN** this change has shipped
- **WHEN** any contract approval / renewal / sign-off / beslissing-op-bezwaar is decided
- **THEN** the decision SHALL be made by a decidesk `Decision`
- **AND** no procest-local approval/besluit-authoring state machine SHALL decide it
- **AND** procest SHALL retain only the ZGW `Besluit` recording + case orchestration
