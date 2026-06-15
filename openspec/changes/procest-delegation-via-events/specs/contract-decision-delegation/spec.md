# contract-decision-delegation Specification

**Status:** proposed
**Scope:** procest
**Tier:** V1
**Depends on:** decidesk event contract (`OCA\Decidesk\Event\DecisionRequestedEvent` /
`OCA\Decidesk\Event\DecisionConcludedEvent`, merged on decidesk development), Nextcloud
`OCP\EventDispatcher\IEventDispatcher`.

## Purpose

Fix the transport that procest uses to delegate contract / besluit / bezwaar / advice **decisions** to
**decidesk**. The delegation policy (decidesk owns the *making* of the decision; procest keeps ZGW case
management and records the ZGW `Besluit` from the outcome; fail closed when decidesk is unavailable) is
unchanged. Only the mechanism changes: the non-existent
`OCA\OpenRegister\Service\IntegrationService::getLeaf(...)->createDecision(payload:...)` registry call
is replaced by an `IEventDispatcher` dispatch of `DecisionRequestedEvent` plus a `DecisionConcludedEvent`
listener that materialises the ZGW `Besluit`.

## MODIFIED Requirements

### Requirement: REQ-PDCD-001 — Contract Decisions Are Raised As decidesk Decisions Via Events

procest SHALL raise a decidesk `Decision` for any contract / bezwaar / advice approval, renewal or
sign-off by dispatching `OCA\Decidesk\Event\DecisionRequestedEvent` through
`OCP\EventDispatcher\IEventDispatcher::dispatchTyped()`, and SHALL persist `getDecisionId()` as the
decisionRef on the case. procest SHALL NOT call the `OCA\OpenRegister\Service\IntegrationService`
registry, `getLeaf()`, or `createDecision(payload:...)`, and SHALL NOT advance a procest-local approval
state machine for the decision.

#### Scenario: Renewal request dispatches a DecisionRequestedEvent

- **GIVEN** a supplier contract within the renewal window and decidesk installed
- **WHEN** a contracts/admin user requests renewal via `ContractController::requestRenewal`
- **THEN** procest SHALL still open the `leverancier-contractverlenging-verzoek` ZGW case
- **AND** procest SHALL `dispatchTyped()` a `DecisionRequestedEvent` with `sourceApp` `procest`
- **AND** the `getDecisionId()` returned on the handled event SHALL be persisted as the case `decisionRef`
- **AND** no procest-local approval state machine SHALL advance the decision

#### Scenario: Bezwaar and advice decisions dispatch the same event

- **GIVEN** a bezwaar or advice request and decidesk installed
- **WHEN** procest delegates the decision via `BezwaarDecisionDelegationService` or `AdviceDelegationService`
- **THEN** procest SHALL dispatch a `DecisionRequestedEvent` (carrying the disposition / advice context in `payload`)
- **AND** procest SHALL NOT resolve decidesk through `IntegrationService::getLeaf`

---

### Requirement: REQ-PDCD-002 — Delegation Fails Closed When decidesk Is Unavailable

procest SHALL fail closed when decidesk cannot handle the decision: if
`class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)` is false (decidesk not installed), OR
the dispatched event returns `isHandled() === false`, OR `getDecisionId()` is null, the delegation SHALL
throw a "decision service unavailable" error and SHALL NOT auto-approve or fall back to a procest-local
approval.

#### Scenario: decidesk not installed blocks the decision

- **GIVEN** decidesk is not installed (the `DecisionRequestedEvent` class does not exist)
- **WHEN** procest attempts to raise a decision
- **THEN** the call SHALL throw a "decision service unavailable" error
- **AND** no contract SHALL be marked approved/renewed and no procest-local approval state SHALL be set

#### Scenario: Unhandled event blocks the decision

- **GIVEN** decidesk is installed but its listener does not handle the event (`isHandled()` false or `getDecisionId()` null)
- **WHEN** procest dispatches the `DecisionRequestedEvent`
- **THEN** the call SHALL throw a "decision service unavailable" error
- **AND** no procest-local approval state SHALL be set as a fallback

---

### Requirement: REQ-PDCD-003 — The ZGW Besluit Is Materialised From The DecisionConcludedEvent

procest SHALL register a listener for `OCA\Decidesk\Event\DecisionConcludedEvent` in
`lib/AppInfo/Application.php`. The listener SHALL filter to `getSourceApp() === 'procest'`, build the
normalised outcome from the event getters (`getStatus()` / `getOutcome()` → Besluit result,
`getDecidedAt()` → `Besluit.datum`, the decision motivering/advice → `Besluit.toelichting`, signers /
signing reference → recorded audit fields) and materialise the ZGW `Besluit` on the case via
`BesluitMaterialisationService`. The Besluiten-API shape SHALL be preserved; ZGW compliance SHALL NOT
regress. The old `consumeOutcome()` / `getDecisionOutcome()` poll path SHALL be removed.

#### Scenario: Concluded decision materialises a ZGW Besluit

- **GIVEN** decidesk dispatches a `DecisionConcludedEvent` with `getSourceApp()` `procest`, `getStatus()` `approved`, an outcome and a decidedAt
- **WHEN** the `DecisionConcludedListener` handles the event
- **THEN** procest SHALL write a ZGW `Besluit` on the matching case via `BesluitMaterialisationService`
- **AND** the `Besluit` SHALL preserve the prior Besluiten-API schema shape

#### Scenario: Event from another source app is ignored

- **GIVEN** a `DecisionConcludedEvent` with `getSourceApp()` not equal to `procest`
- **WHEN** the listener receives the event
- **THEN** procest SHALL ignore it and SHALL NOT materialise a Besluit

#### Scenario: No procest-local poll of decidesk remains

- **GIVEN** this change has shipped
- **WHEN** the source is searched for `consumeOutcome`, `getDecisionOutcome`, `getLeaf` and `OCA\OpenRegister\Service\IntegrationService`
- **THEN** none SHALL remain in `lib/`
