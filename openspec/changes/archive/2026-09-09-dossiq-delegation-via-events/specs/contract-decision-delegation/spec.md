# contract-decision-delegation Specification

**Status:** proposed
**Scope:** dossiq
**Tier:** V1
**Depends on:** decidesk event contract (`OCA\Decidesk\Event\DecisionRequestedEvent` /
`OCA\Decidesk\Event\DecisionConcludedEvent`, merged on decidesk development), Nextcloud
`OCP\EventDispatcher\IEventDispatcher`.

## Purpose

Fix the transport that dossiq uses to delegate contract / besluit / bezwaar / advice **decisions** to
**decidesk**. The delegation policy (decidesk owns the *making* of the decision; dossiq keeps ZGW case
management and records the ZGW `Besluit` from the outcome; fail closed when decidesk is unavailable) is
unchanged. Only the mechanism changes: the non-existent
`OCA\OpenRegister\Service\IntegrationService::getLeaf(...)->createDecision(payload:...)` registry call
is replaced by an `IEventDispatcher` dispatch of `DecisionRequestedEvent` plus a `DecisionConcludedEvent`
listener that materialises the ZGW `Besluit`.

## REMOVED Requirements

**Reason (all three):** the three requirements were written against the decidesk
integration leaf, and this change retires that route: delegation now travels as
a typed `DecisionRequestedEvent` and the outcome comes back as
`DecisionConcludedEvent`. Every scenario under them names the leaf as the
mechanism ("raises a decidesk Decision" through `IntegrationService::getLeaf`,
"the leaf is not registered or returns an error"), a call dossiq no longer
makes. Carrying those scenarios forward would leave the spec asserting a
mechanism the code does not have, so each requirement is removed and re-added
under the same REQ id with the event contract in place of the leaf.

**Migration (all three):** none for a reader of the spec. The three requirements
below keep the REQ-PDCD-001, REQ-PDCD-002 and REQ-PDCD-003 ids and state the
same three rules: decisions are raised in decidesk, delegation fails closed when
decidesk does not answer, and the ZGW Besluit is materialised from the outcome.

### Requirement: REQ-PDCD-001 — Contract Decisions Are Raised As decidesk Decisions

**Reason:** replaced by the event-contract version below.

**Migration:** the leaf call `IntegrationService::getLeaf('decidesk')` becomes a
`dispatchTyped(DecisionRequestedEvent)`; the decision id is read from the
handled event rather than the leaf's return value.

### Requirement: REQ-PDCD-002 — Delegation Fails Closed When decidesk Is Unavailable

**Reason:** replaced by the event-contract version below.

**Migration:** the unavailability test moves from "the leaf is not registered or
returns an error" to "the event class does not exist, the event came back
unhandled, or it carries no decision id".

### Requirement: REQ-PDCD-003 — The ZGW Besluit Is Materialised From The decidesk Outcome

**Reason:** replaced by the event-contract version below.

**Migration:** the outcome arrives as a `DecisionConcludedEvent` on dossiq's
listener rather than being polled from the leaf.

## ADDED Requirements

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
