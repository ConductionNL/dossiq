# contract-decision-delegation Specification

## Purpose
TBD - created by archiving change dossiq-delegate-contract-decision. Update Purpose after archive.

## Requirements

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

### Requirement: REQ-PDCD-001 — Contract Decisions Are Raised As decidesk Decisions Via Events

dossiq SHALL raise a decidesk `Decision` for any contract / bezwaar / advice approval, renewal or
sign-off by dispatching `OCA\Decidesk\Event\DecisionRequestedEvent` through
`OCP\EventDispatcher\IEventDispatcher::dispatchTyped()`, and SHALL persist `getDecisionId()` as the
decisionRef on the case. dossiq SHALL NOT call the `OCA\OpenRegister\Service\IntegrationService`
registry, `getLeaf()`, or `createDecision(payload:...)`, and SHALL NOT advance a dossiq-local approval
state machine for the decision.

#### Scenario: Renewal request dispatches a DecisionRequestedEvent

- **GIVEN** a supplier contract within the renewal window and decidesk installed
- **WHEN** a contracts/admin user requests renewal via `ContractController::requestRenewal`
- **THEN** dossiq SHALL still open the `leverancier-contractverlenging-verzoek` ZGW case
- **AND** dossiq SHALL `dispatchTyped()` a `DecisionRequestedEvent` with `sourceApp` `dossiq`
- **AND** the `getDecisionId()` returned on the handled event SHALL be persisted as the case `decisionRef`
- **AND** no dossiq-local approval state machine SHALL advance the decision

#### Scenario: Bezwaar and advice decisions dispatch the same event

- **GIVEN** a bezwaar or advice request and decidesk installed
- **WHEN** dossiq delegates the decision via `BezwaarDecisionDelegationService` or `AdviceDelegationService`
- **THEN** dossiq SHALL dispatch a `DecisionRequestedEvent` (carrying the disposition / advice context in `payload`)
- **AND** dossiq SHALL NOT resolve decidesk through `IntegrationService::getLeaf`

---

### Requirement: REQ-PDCD-002 — Delegation fails closed when decidesk does not answer the event

dossiq SHALL fail closed when decidesk cannot handle the decision: if
`class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)` is false (decidesk not installed), OR
the dispatched event returns `isHandled() === false`, OR `getDecisionId()` is null, the delegation SHALL
throw a "decision service unavailable" error and SHALL NOT auto-approve or fall back to a dossiq-local
approval.

#### Scenario: decidesk not installed blocks the decision

- **GIVEN** decidesk is not installed (the `DecisionRequestedEvent` class does not exist)
- **WHEN** dossiq attempts to raise a decision
- **THEN** the call SHALL throw a "decision service unavailable" error
- **AND** no contract SHALL be marked approved/renewed and no dossiq-local approval state SHALL be set

#### Scenario: Unhandled event blocks the decision

- **GIVEN** decidesk is installed but its listener does not handle the event (`isHandled()` false or `getDecisionId()` null)
- **WHEN** dossiq dispatches the `DecisionRequestedEvent`
- **THEN** the call SHALL throw a "decision service unavailable" error
- **AND** no dossiq-local approval state SHALL be set as a fallback

---

### Requirement: REQ-PDCD-003 — The ZGW Besluit Is Materialised From The DecisionConcludedEvent

dossiq SHALL register a listener for `OCA\Decidesk\Event\DecisionConcludedEvent` in
`lib/AppInfo/Application.php`. The listener SHALL filter to `getSourceApp() === 'dossiq'`, build the
normalised outcome from the event getters (`getStatus()` / `getOutcome()` → Besluit result,
`getDecidedAt()` → `Besluit.datum`, the decision motivering/advice → `Besluit.toelichting`, signers /
signing reference → recorded audit fields) and materialise the ZGW `Besluit` on the case via
`BesluitMaterialisationService`. The Besluiten-API shape SHALL be preserved; ZGW compliance SHALL NOT
regress. The old `consumeOutcome()` / `getDecisionOutcome()` poll path SHALL be removed.

#### Scenario: Concluded decision materialises a ZGW Besluit

- **GIVEN** decidesk dispatches a `DecisionConcludedEvent` with `getSourceApp()` `dossiq`, `getStatus()` `approved`, an outcome and a decidedAt
- **WHEN** the `DecisionConcludedListener` handles the event
- **THEN** dossiq SHALL write a ZGW `Besluit` on the matching case via `BesluitMaterialisationService`
- **AND** the `Besluit` SHALL preserve the prior Besluiten-API schema shape

#### Scenario: Event from another source app is ignored

- **GIVEN** a `DecisionConcludedEvent` with `getSourceApp()` not equal to `dossiq`
- **WHEN** the listener receives the event
- **THEN** dossiq SHALL ignore it and SHALL NOT materialise a Besluit

#### Scenario: No dossiq-local poll of decidesk remains

- **GIVEN** this change has shipped
- **WHEN** the source is searched for `consumeOutcome`, `getDecisionOutcome`, `getLeaf` and `OCA\OpenRegister\Service\IntegrationService`
- **THEN** none SHALL remain in `lib/`
