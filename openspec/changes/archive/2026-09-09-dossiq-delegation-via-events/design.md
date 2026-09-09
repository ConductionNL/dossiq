# Design — dossiq delegation via IEventDispatcher events

## Context

`dossiq-delegate-contract-decision` + `dossiq-delegate-remaining-decisions-to-decidesk` (archived)
delegate decision *making* to decidesk and assumed an ADR-019 integration-registry transport:
`container->get('OCA\OpenRegister\Service\IntegrationService')->getLeaf(name:'decidesk')` then
`->createDecision(payload:...)`. That class/method pair does not exist — the call always threw, was
caught in `resolveIntegrationService()`, and returned `null`, so delegation silently fail-closed and
never reached decidesk. decidesk has now merged a concrete **event** contract; this change adopts it.

## The decidesk event contract (verbatim, used exactly)

**Dispatch (dossiq → decidesk), synchronous in-process via `IEventDispatcher::dispatchTyped()`:**

- `OCA\Decidesk\Event\DecisionRequestedEvent` extends `OCP\EventDispatcher\Event`.
- ctor `(string $sourceApp, string $subjectRegister, string $subjectSchema, string $subjectId, string $subjectLabel = '', string $decisionType = 'contract', string $actorId = '', array $payload = [], string $externalReference = '', string $correlationId = '')`.
- `payload` keys consumed: `title`, `text`, `decisionDate`, `outcome`.
- After dispatch, read `isHandled():bool` + `getDecisionId():?string`. If `isHandled()` is false OR
  `getDecisionId()` is null → decidesk did not handle it → **fail closed (throw)**.

**Listen (decidesk → dossiq) for the terminal outcome:**

- `OCA\Decidesk\Event\DecisionConcludedEvent` extends Event.
- Getters: `getDecisionId()`, `getDecisionType()`, `getStatus()` (`approved|rejected|withdrawn|pending`),
  `getOutcome()`, `isSigned()`, `getSigningReference():?string`, `getSigners():array`,
  `getDecidedAt():?string`, `getSourceApp()`, `getSubjectRegister():?string`, `getSubjectSchema():?string`,
  `getSubjectId():?string`, `getExternalReference()`, `getCorrelationId()`.
- Register via `$context->registerEventListener(DecisionConcludedEvent::class, DecisionConcludedListener::class)`;
  filter `if ($event->getSourceApp() !== 'dossiq') return;` then project onto the domain record.

## Decisions

1. **Reference the event classes by string in the listener registration but by FQN in the dispatch
   guard.** dossiq already registers OpenRegister approval events by FQN string
   (`'OCA\OpenRegister\Event\ApprovalStepApprovedEvent'`) to avoid a hard compile-time dependency on an
   optional app. decidesk is likewise optional, so `DecisionConcludedListener` is registered against the
   class-string `\OCA\Decidesk\Event\DecisionConcludedEvent::class` only when present; in the dispatch
   path the delegation service guards with `class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)`
   and fails closed when false. This keeps dossiq installable without decidesk.

2. **Keep the delegation services' public method signatures.** `raiseContractDecision()`,
   `raiseDecision()`, `raiseBezwaarDecision()`, `raiseAdviceDecision()`, `raiseVoorstelBesluit()` all
   keep returning the decisionRef string. Only the body changes (dispatch instead of registry call).
   This means none of the ~8 callers (ContractRenewalService, AdviceService, ConsultationService,
   Bezwaar/DecisionService, VoorstelBesluitController, AdvisoryCommitteeService, the two Repair steps)
   need to change. The decisionRef is now `DecisionRequestedEvent::getDecisionId()`.

3. **The outcome arrives by event, not by poll.** The old `consumeOutcome()` /
   `getDecisionOutcome()` poll path is removed. `DecisionConcludedListener` receives the full outcome on
   the `DecisionConcludedEvent` and drives `BesluitMaterialisationService`. The one external caller of
   `consumeOutcome()` — `Bezwaar/DecisionService::applyToBezwaar()` — is updated so it no longer polls
   decidesk; the listener now owns Besluit materialisation, and `applyToBezwaar()` only runs the status
   transition (its other responsibility). This preserves the fail-closed "do not author a besluit
   locally" intent.

4. **`BesluitMaterialisationService` gains a thin `materialiseFromConcludedEvent()` mapper** that
   converts the event getters into the existing normalised-outcome array shape
   (`result/decidedAt/motivering/signer/method/raw`) and calls the existing `materialise()`. The
   Besluiten-API payload shape (`buildBesluitPayload`) is untouched, so ZGW compliance does not regress.
   `getStatus()` (approved/rejected/withdrawn/pending) maps to a ZGW result via `getOutcome()` (verbatim
   decidesk result string) with a status fallback. The listener resolves the target case + existing
   besluitRef from the domain record carrying the matching `decisionRef` (matched by
   `getDecisionId()`), falling back to `getExternalReference()` / `getSubjectId()`.

## Fail-closed invariants (unchanged)

- decidesk absent (`class_exists` false) → throw, never proceed.
- `isHandled()` false or `getDecisionId()` null → throw, never proceed.
- No path sets a dossiq-local "approved" state as a fallback.
- The `DecisionConcludedListener` swallows + logs its own derivation failures (so a defective lookup
  never blocks event delivery) but NEVER authors a besluit on a failed/absent outcome.

## Risks

- Local OCP stub is stale (known fleet gotcha) → deep Psalm/PHPStan may emit phantom errors about
  `IEventDispatcher` / `Event`; rely on CI for the deep pass, `php -l` locally.
- `pending` status on a `DecisionConcludedEvent` is non-terminal — the listener treats only
  approved/rejected/withdrawn as materialisable and ignores `pending` (no besluit yet).
