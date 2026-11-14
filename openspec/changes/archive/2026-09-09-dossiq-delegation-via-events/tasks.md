# Tasks — Delegate dossiq decisions to decidesk via IEventDispatcher events

## Phase 1: Dispatch path (dossiq → decidesk)

- [ ] Inject `OCP\EventDispatcher\IEventDispatcher` into `ContractDecisionDelegationService`.
- [ ] Rewrite `raiseContractDecision()` to build + `dispatchTyped()` a
      `OCA\Decidesk\Event\DecisionRequestedEvent` (guarded by `class_exists(...)`), read back
      `isHandled()` / `getDecisionId()`, return the decisionId; fail closed (throw) when decidesk is
      absent or the event is not handled.
- [ ] Rewrite `raiseDecision()` (shared core used by the bezwaar/advice siblings) the same way.
- [ ] Delete `resolveIntegrationService()`, `consumeOutcome()`, and all `createDecision(payload:...)` /
      `getDecisionOutcome(...)` calls. Drop the now-unused `IAppManager` / `ContainerInterface` deps if
      no longer referenced.
- [ ] Keep the public method signatures of `BezwaarDecisionDelegationService`,
      `AdviceDelegationService` (they delegate to the core — transparent switch).

## Phase 2: Listen path (decidesk → dossiq)

- [ ] Add `lib/Listener/DecisionConcludedListener.php` implementing `IEventListener`, filtering
      `getSourceApp() === 'dossiq'`, building the normalised outcome from the event getters, and
      driving `BesluitMaterialisationService` to materialise the ZGW Besluit. Swallow+log its own
      derivation failures; never author a besluit on an absent/failed outcome.
- [ ] Add `BesluitMaterialisationService::materialiseFromConcludedEvent()` that maps the event getters
      to the existing normalised-outcome array and calls the existing `materialise()` (Besluiten-API
      shape unchanged).
- [ ] Register the listener for `\OCA\Decidesk\Event\DecisionConcludedEvent::class` in
      `lib/AppInfo/Application.php` (registered only when decidesk is present — class-string guard).
- [ ] Update `Bezwaar/DecisionService::applyToBezwaar()` to stop polling decidesk via `consumeOutcome()`
      (the listener now owns Besluit materialisation); keep the status transition it also performs.

## Phase 3: Cleanup + verify

- [ ] `grep -rn "getLeaf|OCA\\OpenRegister\\Service\\IntegrationService|createDecision(payload:|consumeOutcome|getDecisionOutcome" lib/`
      returns nothing.
- [ ] `php -l` every changed PHP file.
- [ ] Run the hydra mechanical gates (`scripts/run-hydra-gates.sh`); report results.
- [ ] `openspec validate dossiq-delegation-via-events --strict` exits 0.
