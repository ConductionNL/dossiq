# contract-decision-delegation Specification

## Purpose
TBD - created by archiving change dossiq-delegate-contract-decision. Update Purpose after archive.
## Requirements
### Requirement: REQ-PDCD-001 — Contract Decisions Are Raised As decidesk Decisions

dossiq SHALL raise a **decidesk `Decision`** for any contract approval / renewal / sign-off via the
OpenRegister integration registry (ADR-019), through a new `ContractDecisionDelegationService`.
dossiq SHALL NOT advance a dossiq-local approval state machine for the decision after this change.

#### Scenario: Renewal request raises a decidesk Decision

- **GIVEN** a supplier contract within the 90-day renewal window and the `decidesk` integration leaf available
- **WHEN** a contracts/admin user requests renewal via `ContractController::requestRenewal`
- **THEN** dossiq SHALL still open the `leverancier-contractverlenging-verzoek` ZGW case
- **AND** dossiq SHALL call `ContractDecisionDelegationService::raiseContractDecision(...)`, creating a decidesk `Decision`
- **AND** the returned `decisionRef` SHALL be persisted on the case
- **AND** no dossiq-local approval state machine SHALL advance the decision

#### Scenario: Beslissing-op-bezwaar raises a decidesk Decision

- **GIVEN** a bezwaar case and the `decidesk` integration leaf available
- **WHEN** a behandelaar submits `BezwaarDecisionForm.vue`
- **THEN** dossiq SHALL raise a decidesk `Decision` carrying the disposition, motivation and follows-advice values
- **AND** dossiq SHALL NOT author the besluit through a dossiq-local besluit engine

---

### Requirement: REQ-PDCD-002 — Delegation Fails Closed When decidesk Is Unavailable

The `ContractDecisionDelegationService` SHALL **fail closed** when the `decidesk` integration leaf is
unavailable: it SHALL surface a clear "decision service unavailable" error and SHALL NOT auto-approve
or fall back to a dossiq-local approval. (Mirrors the `unsafe-auth-resolver` rule — an unavailable
decision service is not "decision skipped".)

#### Scenario: decidesk leaf unavailable blocks the decision

- **GIVEN** the `decidesk` integration leaf is not registered or returns an error
- **WHEN** dossiq attempts to raise a contract decision
- **THEN** the call SHALL fail with a "decision service unavailable" error
- **AND** no contract SHALL be marked approved/renewed
- **AND** no dossiq-local approval state SHALL be set as a fallback

---

### Requirement: REQ-PDCD-003 — The ZGW Besluit Is Materialised From The decidesk Outcome

dossiq SHALL materialise the ZGW `Besluit` on the case **from the decidesk Decision outcome**, not
from a dossiq-local besluit-authoring path. The materialised `Besluit` SHALL preserve the Besluiten-API
shape: decidesk `result` → Besluit result, decidesk `decidedAt` → `Besluit.datum`, decidesk
motivering/advice → `Besluit.toelichting`, decidesk signer/mandaathouder + method → recorded audit
fields. ZGW compliance SHALL NOT regress.

#### Scenario: Approved decidesk Decision materialises a ZGW Besluit

- **GIVEN** a decidesk `Decision` for a contract reaches outcome `verleend` with a datum, motivering and mandaathouder
- **WHEN** dossiq consumes the outcome via `ContractDecisionDelegationService::consumeOutcome(...)`
- **THEN** dossiq SHALL write a ZGW `Besluit` on the case with result `verleend`, the decided datum, and the motivering as `toelichting`
- **AND** the `Besluit` SHALL match the prior Besluiten-API schema shape (verified by a contract test)

#### Scenario: Rejected decidesk Decision is recorded on the case file

- **GIVEN** a decidesk `Decision` reaches outcome `geweigerd`
- **WHEN** dossiq consumes the outcome
- **THEN** dossiq SHALL record a `Besluit` with result `geweigerd` and the motivering on the case dossier

---

### Requirement: REQ-PDCD-004 — dossiq Keeps ZGW Case Management And The Expiry Scan

dossiq SHALL keep ZGW case management unchanged: the contract remains a zaak; the nightly
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
- **THEN** dossiq SHALL return 403 (IDOR fail-closed scope preserved, unchanged by this delegation)

---

### Requirement: REQ-PDCD-005 — The Besluitvorming Engine Is Narrowed To ZGW-Record

dossiq SHALL narrow its besluitvorming endpoints to ZGW-record / case-orchestration roles and SHALL
stop authoring decisions locally. `PublicationController::publish` SHALL publish the recorded ZGW
`Besluit` (fed by the decidesk outcome) rather than author it; `BesluitvormingController::activateTemplate`
and the `bvw-*` decision-type templates SHALL be deprecated in favour of decidesk decisionTypes;
`AgendaController` agenda endpoints SHALL remain as ZGW case orchestration. Endpoints that remain
routable for deep-link/back-compat after their decision role is removed SHALL stay registered.

#### Scenario: Publish operates on the recorded Besluit, not a local authoring path

- **GIVEN** a case whose `Besluit` was materialised from a decidesk outcome
- **WHEN** `PublicationController::publish` is called
- **THEN** dossiq SHALL publish the already-recorded ZGW `Besluit` on the requested channel
- **AND** dossiq SHALL NOT author a new besluit through a dossiq-local besluit engine

#### Scenario: bvw decision-type templates are deprecated

- **GIVEN** the `bvw-mandaatbesluit`, `bvw-college-besluit` and `bvw-raadsbesluit` templates
- **WHEN** this change ships
- **THEN** the templates SHALL be marked deprecated and decision *types* SHALL come from decidesk decisionTypes
- **AND** existing template rows SHALL remain readable until sunset

---

### Requirement: REQ-PDCD-006 — Mandate Checking Delegates To The decidesk Decision Route

dossiq SHALL delegate "is the signing user mandated?" to the decidesk decision route/stage assignee
model. `MandaatController::mandaatCheck` SHALL be reduced to a thin read-through of the decidesk
mandate answer (or removed once callers migrate); dossiq SHALL NOT maintain a parallel mandate
authority for contract/besluit decisions.

#### Scenario: Mandate answer comes from decidesk

- **GIVEN** a contract/besluit decision raised in decidesk with a route stage assigned to a mandated role
- **WHEN** dossiq checks whether a signing user is mandated
- **THEN** dossiq SHALL obtain the mandate answer from the decidesk decision route/stage assignee model
- **AND** dossiq SHALL NOT compute the mandate from a dossiq-local mandate engine for that decision

---

### Requirement: REQ-PDCD-007 — In-Flight Contract Cases Are Migrated Without Data Loss

dossiq SHALL provide a `lib/Repair/*` step that links in-flight contract / besluitvorming cases
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

