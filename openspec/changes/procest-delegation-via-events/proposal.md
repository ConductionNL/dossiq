# Proposal: procest-delegation-via-events

kind: defect-fix / mechanism switch — cites **ADR-019** (cross-app integration), **ADR-022**
(apps-consume-or-abstractions) and **ADR-012** (deduplication). This change does NOT re-open the
delegation policy decided in `procest-delegate-contract-decision` and
`procest-delegate-remaining-decisions-to-decidesk` (both archived) — it FIXES the broken transport
those changes assumed.

## Summary

The delegation of contract / besluit / bezwaar / advice **decisions** from procest to **decidesk**
was implemented against a transport that does not exist. `ContractDecisionDelegationService`
resolves decidesk by calling
`container->get('OCA\OpenRegister\Service\IntegrationService')->getLeaf(name:'decidesk')` and then
`->createDecision(payload:...)`. There is no `OCA\OpenRegister\Service\IntegrationService` class and
no `getLeaf()` / `createDecision()` method — every call throws, is caught, and `resolveIntegrationService()`
returns `null`, so delegation **always fail-closes and never reaches decidesk**. The flow is only
"safe" because it fails closed; it has never actually delegated a decision.

decidesk has since MERGED a concrete event contract on its `development` branch. The chosen transport
is the Nextcloud **`IEventDispatcher`**: a consumer dispatches `OCA\Decidesk\Event\DecisionRequestedEvent`
synchronously, decidesk's in-process listener handles it and writes the result back onto the event
(`isHandled()` + `getDecisionId()`), and decidesk later dispatches `OCA\Decidesk\Event\DecisionConcludedEvent`
carrying the terminal outcome. This change rewires procest's delegation services onto that contract.

**What changes:**

1. `ContractDecisionDelegationService` injects `OCP\EventDispatcher\IEventDispatcher`. `raiseContractDecision()`
   and `raiseDecision()` build and `dispatchTyped()` a `DecisionRequestedEvent`
   (guarded by `class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)` — fail closed when
   decidesk is absent), then read back `isHandled()` / `getDecisionId()`. The dead `getLeaf` /
   `IntegrationService` / `createDecision(payload:...)` / `getDecisionOutcome` / `resolveIntegrationService()`
   / `consumeOutcome()` paths are removed.
2. A new `DecisionConcludedListener` listens for `DecisionConcludedEvent`, filters
   `getSourceApp() === 'procest'`, builds the normalised outcome from the event getters, and drives
   `BesluitMaterialisationService` to materialise the ZGW `Besluit` from the outcome. This REPLACES the
   old poll-and-consume (`consumeOutcome` / `getDecisionOutcome`) projection path. The listener is
   registered in `lib/AppInfo/Application.php`.
3. The sibling services (`BezwaarDecisionDelegationService`, `AdviceDelegationService`) keep their
   public signatures — they delegate to the shared core, so the transport switch is transparent to them.

**What stays unchanged:** every Awb / ZGW domain rule, the fail-closed semantics (never auto-approve),
the public method signatures of the delegation services (callers in `ContractRenewalService`,
`AdviceService`, `ConsultationService`, `Bezwaar/DecisionService`, `VoorstelBesluitController`,
`AdvisoryCommitteeService`, and the `lib/Repair/*` link steps are untouched), and the ZGW Besluiten-API
shape `BesluitMaterialisationService` writes.

## Why

The delegation never worked. `getLeaf`/`IntegrationService`/`createDecision` are phantom APIs; the
service has silently fail-closed in production since it shipped. decidesk's real, merged contract is
event-based. Aligning procest onto the event contract is the minimal correct fix and removes the
phantom-API dead code that the `unsafe-auth-resolver` and `redundant-controller` hydra gates would
otherwise (correctly) flag as a fail-open-shaped resolver wrapping a non-existent abstraction.

## What

1. **Dispatch via `IEventDispatcher`.** Inject the dispatcher, build `DecisionRequestedEvent` with
   positional/named ctor args (`sourceApp='procest'`, subjectRegister/Schema/Id/Label, decisionType,
   actorId, payload, externalReference, correlationId), `dispatchTyped()`, then fail closed unless
   `isHandled()` is true AND `getDecisionId()` is non-null. Return the decisionId as the decisionRef.
2. **Listen via `DecisionConcludedEvent`.** Register `DecisionConcludedListener`; filter
   `getSourceApp()==='procest'`; map the event getters (status/outcome/decidedAt/signers/signing) to the
   normalised outcome and call `BesluitMaterialisationService::materialiseFromConcludedEvent(...)`.
3. **Remove dead transport code.** Delete `resolveIntegrationService()`, `consumeOutcome()`,
   `getDecisionOutcome` usage, and the `createDecision(payload:...)` calls. Grep-confirm `getLeaf` and
   `OCA\OpenRegister\Service\IntegrationService` are gone from `lib/`.

## Capabilities

### Modified Capabilities

- `contract-decision-delegation` — the delegation transport changes from the (non-existent) ADR-019
  `IntegrationService::getLeaf`/`createDecision` registry call to `IEventDispatcher` dispatch of
  `DecisionRequestedEvent` + a `DecisionConcludedEvent` listener that materialises the ZGW Besluit.
  The policy (delegate the deciding to decidesk, keep ZGW case management + Besluit recording, fail
  closed) is unchanged.

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo.
- [x] Project: `decidesk` — provides the merged event contract (`DecisionRequestedEvent` /
  `DecisionConcludedEvent`); no procest-side code in decidesk.

## Out of Scope

- The decidesk-side listener/dispatcher (merged on decidesk development).
- Re-opening the delegation *policy* (which decisions delegate, ZGW Besluit recording, the
  bvw-template/DecisionTypes deprecation) — settled in the two archived delegate-* changes.
- Re-routing the supplier portal, expiry scan, or status engine.

## Success Criteria

- `openspec validate procest-delegation-via-events --strict` exits 0.
- `grep -rn "getLeaf\|OCA\\\\OpenRegister\\\\Service\\\\IntegrationService\|createDecision(payload:" lib/`
  returns nothing.
- Raising a contract/bezwaar/advice decision dispatches `DecisionRequestedEvent` and returns the
  decidesk `getDecisionId()`; an absent decidesk (class_exists false) or `isHandled()==false`/null id
  fails closed (throws) and no decision is auto-approved.
- A `DecisionConcludedEvent` with `getSourceApp()==='procest'` materialises the ZGW `Besluit` from the
  outcome via `BesluitMaterialisationService`; events from other source apps are ignored.
