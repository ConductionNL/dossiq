# Tasks: parafering-runtime-to-decidiq

> The dossiq half of the parafering-runtime move. Counterpart:
> decidiq's `parafering-route-runtime`, which MUST merge FIRST — dossiq deletes
> its local engine assuming decidiq's already holds the runtime.

## Implementation Tasks

### Task 1: The raise
- **spec_ref**: `openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md#requirement-req-prtd-001-entering-parafering-is-a-raise-and-it-fails-closed`
- **files**: `lib/Service/Parafeer/ParaferingRaiseService.php`, `lib/Service/Transitions/BesluitvormingActivateHandler.php`
- [x] Implement
- [x] Test — the two refusals (unroutable, decision app absent) are pinned

### Task 2: The conclusion
- **spec_ref**: `.../spec.md#requirement-req-prtd-002-a-concluded-chain-is-recorded-as-case-data`
- **files**: `lib/Service/Parafeer/ParaferingConclusionService.php`, `lib/Listener/ParaferingConcludedListener.php`, `lib/AppInfo/Registrar/WorkflowListenerRegistrar.php`, `tests/Stubs/{Decidiq,Decidesk}/Event/ApprovalRouteConcludedEvent.php`
- [x] Implement
- [x] Test — mutation-checked: dropping onBehalfOf preservation, dropping the dedup, and dropping the terminal-status idempotency each turn a named test red

### Task 3: Retire the local runtime, no facade
- **spec_ref**: `.../spec.md#requirement-req-prtd-003-no-local-parafering-runtime-returns`
- **files**: deleted — BesluitvormingParafeerService, ParafeerActieService, ParafeerStepGuard, ParaferingActionMapper, ParaferingFlowGateway, EndorsementRouteFlowMigrator, ParaafFlowLinkage, ParaafResumeListener, DossiqAskParaafNode, DossiqSetVoorstelStatusNode, ParafeerActieController, MigrateApprovalRoutesToFlowsCommand; rewired — DossiqFlowNodeListener, appinfo/routes.php, appinfo/info.xml
- [x] Implement
- [x] Test — `LocalParaferingRuntimeTest` catches any retired class or new local advancer

### Task 4: The in-flight migration
- **spec_ref**: `.../spec.md#requirement-req-prtd-004-in-flight-paraferingen-are-re-raised-on-upgrade`
- **files**: `lib/Repair/RaiseInFlightParaferingenInDecidiq.php`, `appinfo/info.xml`
- [x] Implement
- [x] Test — selection, skips and the no-identity failure are pinned

### Task 5: Retire the front-end sign-off surface
- **spec_ref**: `.../spec.md#requirement-req-prtd-003-no-local-parafering-runtime-returns`
- **files**: deleted — `src/dialogs/ParafeerActieDialog.vue`, `src/dialogs/SkipStepDialog.vue`, `src/views/voorstellen/components/DelegateSelectorField.vue`, `src/utils/parafeerEngine.js`; reworked — `src/views/voorstellen/VoorstelDetail.vue` (read-only history + returned notice), `src/services/parafeerActieApi.js` (listActions reads OpenRegister; no recordAction)
- [x] Implement — builds clean; the sign-off UI was not e2e-covered, and the e2e that asserts the activation refusal still holds
