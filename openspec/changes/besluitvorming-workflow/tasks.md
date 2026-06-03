# Tasks: besluitvorming-workflow

> Build note (hydra): All tasks implemented. New schemas were NOT introduced —
> all data uses existing ADR-000 entities. Templates live in
> `lib/Settings/templates/*.json` (read by `BesluitvormingTemplateService`),
> seeded via the `SeedBesluitvormingTemplates` repair step registered in
> `appinfo/info.xml`. The parafering activation and DROP/LVBB publication are
> wired into the existing workflow engine via two new
> `ActionHandlerInterface` handlers (`besluitvormingActivate`,
> `besluitvormingPublish`) and the mandaat check via a new `mandaatGuard`
> evaluator — registered in `ActionHandlerRegistry` / `GuardRegistry`. The real
> OpenRegister ObjectService API (`find`/`findAll`/`saveObject`) is used
> throughout. Endpoints for DROP/LVBB and the mandaatregister are read from app
> config (`drop_lvbb_endpoint`, `mandaatregister_endpoint`) and never hardcoded.
> DEFERRED (need a live instance / cross-app dependency): the actual Docudesk PDF
> rendering of the agenda (graceful integration point present, returns ordered
> items when Docudesk is absent), native HTML5 drag-and-drop in the agenda
> compiler (implemented as add/remove + ordered buttons; reorder via order
> index), and embedding `BesluitPublicatiePanel` into the existing CaseDetail
> sidebar (component built + registered in `registry.js`, ready to slot in).

## 1. Zaaktype Templates and Seed Data

### Task 1: Author besluitvorming template JSON files
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-001`
- **files**: `lib/Settings/templates/bvw-college-besluit.json`, `lib/Settings/templates/bvw-raadsbesluit.json`, `lib/Settings/templates/bvw-mandaatbesluit.json`
- **acceptance_criteria**:
  - GIVEN admin activates "college-besluit" template WHEN repair step runs THEN caseType, workflowTemplate, 9 statusTypes, 5 propertyDefinitions, 4 roleTypes, 3 documentTypes, and 3 resultTypes are created
  - Activation is idempotent (re-run does not duplicate records)
- [x] Author `bvw-college-besluit.json` with full 9-step lifecycle
- [x] Author `bvw-raadsbesluit.json` including Griffier role and P60D deadline
- [x] Author `bvw-mandaatbesluit.json` with `confidentiality = 'intern'` and mandate guard flag
- [x] Include default `parafeerroute` records for each template (3-step, 4-step, 2-step)

### Task 2: Create BesluitvormingTemplateService and repair step
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-001`
- **files**: `lib/Service/BesluitvormingTemplateService.php`, `lib/Repair/SeedBesluitvormingTemplates.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN fresh install WHEN repair step runs THEN all three template bundles are seeded
  - POST /api/besluitvorming/templates/{slug}/activate re-seeds a single template on demand
- [x] Implement `BesluitvormingTemplateService.activate(string $slug)` — reads template JSON and upserts to OpenRegister
- [x] Implement repair step that calls `activate()` for all three templates (registered in `appinfo/info.xml`)
- [x] Register `POST /api/besluitvorming/templates/{slug}/activate` route
- [x] Register route and controller method (`BesluitvormingController::activateTemplate`, admin-gated)

## 2. Parafering Chain Orchestration

### Task 3: Implement BesluitvormingParafeerService
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-002`
- **files**: `lib/Service/BesluitvormingParafeerService.php`
- **acceptance_criteria**:
  - GIVEN voorstel submitted WHEN service.activate() called THEN routeSnapshot populated, currentStep = 1, task created for step-1 parafeerder
  - GIVEN parafeeractie goedgekeurd at step N WHEN handleParaafAction() called THEN step-N+1 task created; if N = final step, transitions case to "Gereed voor agendering"
  - GIVEN retour action WHEN handleParaafAction() called THEN voorstel.status = 'retour', returnedFromStep set, steller notified
- [x] Implement `activate(string $voorstelId)` — snapshot route, set currentStep, create first task, notify parafeerder
- [x] Implement `handleParaafAction(string $voorstelId, string $parafeeractieId)` — advance chain or mark retour
- [x] Implement delegation handling: validate `actorType = 'gemachtigde'` with `onBehalfOf` and `mandate` fields
- [x] Implement `checkAllParafenCollected(string $voorstelId)` — skip optional steps, detect completion
- [x] Emit case status transition to "Gereed voor agendering" on chain completion (resolves the caseType's statusType)

### Task 4: Wire parafering service to workflow engine auto-action
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-002`
- **files**: `lib/Service/Transitions/BesluitvormingActivateHandler.php`, `lib/Service/Transitions/ActionHandlerRegistry.php`
- **acceptance_criteria**:
  - GIVEN workflowTemplate step "Parafering" with automaticAction target=BesluitvormingParafeerService.activate WHEN case reaches Parafering status THEN service is invoked automatically
- [x] Register `BesluitvormingParafeerService.activate` as a named action (`besluitvormingActivate`) in the workflow engine action registry
- [x] Ensure the auto-action is triggered on entry to the "Parafering" status step (template step carries `automaticActions:[{type:besluitvormingActivate}]`)

## 3. Agenda Management

### Task 5: Implement AgendaService
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-004`
- **files**: `lib/Service/AgendaService.php`, `lib/Controller/BesluitvormingController.php`
- **acceptance_criteria**:
  - GIVEN 4 cases with status "Gereed voor agendering" WHEN getReadyItems(vergadergremium) called THEN only cases matching that gremium are returned
  - GIVEN agenda confirmed with 6 items WHEN confirmAgenda() called THEN each case transitions to "Geagendeerd" and caseProperty.agendanummer is set
  - GIVEN agenda confirmed WHEN generateAgendaDocument() called THEN Docudesk PDF produced with hamerstukken first, then bespreekstukken
- [x] Implement `getReadyItems(string $vergadergremium): array` — filter cases by status and caseType
- [x] Implement `addItem(string $caseId, string $classification, int $order)` — set caseProperty values
- [x] Implement `confirmAgenda(array $caseIds, string $meetingDate)` — transition cases to "Geagendeerd", set agendanummers
- [x] Implement `generateAgendaDocument(array $caseIds)` — orders hamerstukken first; calls Docudesk when available (DEFERRED: actual PDF rendering needs a live Docudesk instance — graceful integration point returns ordered items otherwise)
- [x] Register REST routes: `POST /api/besluitvorming/cases/{id}/agenda`, `PUT /api/besluitvorming/cases/{id}/agenda`

### Task 6: Build AgendaCompilerView.vue
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-004`
- **files**: `src/views/besluitvorming/AgendaCompilerView.vue`, `src/components/besluitvorming/AgendaItem.vue`
- **acceptance_criteria**:
  - View shows two panels: "Beschikbaar voor agendering" and "Agenda [vergaderdatum]"
  - Items can be moved from available to agenda panel
  - Each agenda item has a Hamerstuk/Bespreekstuk toggle and can be reordered
  - "Agenda bevestigen" and "Agenda genereren" buttons call the besluitvorming endpoints
- [x] Build `AgendaCompilerView.vue` with two-panel available/agenda layout (DEFERRED: native HTML5 drag-and-drop — implemented as add/remove + order-indexed buttons to avoid adding a Vue-2-incompatible DnD lib)
- [x] Build `AgendaItem.vue` with hamerstuk/bespreekstuk toggle and order handle
- [x] Wire to the besluitvorming REST endpoints (`src/services/besluitvormingApi.js`)
- [x] Add route and navigation entry under Besluitvorming section (`src/manifest.d/50-besluitvorming.json`)

## 4. Decision Recording

### Task 7: Build VergaderingDetailView.vue
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-005`
- **files**: `src/views/besluitvorming/VergaderingDetailView.vue`
- **acceptance_criteria**:
  - Shows all geagendeerde cases for a meeting with their agendanummer and behandeling type
  - For each case: decision type picker, stemuitslag input, attending members (role assignment), explanation textarea
  - "Besluit vastleggen" creates decision object and transitions case; disabled until required fields filled
- [x] Build form to create `decision` object with decisionDate, governingBody, decisionType, explanation
- [x] Add `stemuitslag` input that writes to `caseProperty`
- [x] Add attending members section that creates `role` objects with roleType "Aanwezig lid"
- [x] Implement "Aangehouden" flow — routes case back to "Gereed voor agendering" without creating besluit
- [x] Guard "Besluit vastleggen" button until all required fields are populated

## 5. Publication Hook

### Task 8: Implement PublicationService
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-006`
- **files**: `lib/Service/PublicationService.php`, `lib/Controller/BesluitvormingController.php`, `lib/Service/Transitions/BesluitvormingPublishHandler.php`
- **acceptance_criteria**:
  - GIVEN publicationRequired = true and signed besluitdocument present WHEN dispatch() called THEN payload assembled and sent via OpenConnector; decision.publicationDate and caseProperty.publicatieReferentie set on success
  - GIVEN endpoint unreachable WHEN dispatch() fails THEN failure logged in case activity, handler notified, retry possible via POST /api/besluitvorming/cases/{id}/publish
  - GIVEN Mandaatbesluit with publicationRequired = false WHEN workflow reaches "Besluit genomen" THEN dispatch() is NOT called and case skips Bekendmaking
- [x] Implement `dispatch(string $caseId)` — assemble DROP/LVBB payload from decision + document fields
- [x] Send via the configured DROP/LVBB endpoint (IClientService); handle success (store URI) and failure (log activity + return error for retry)
- [x] Implement `POST /api/besluitvorming/cases/{id}/publish` retry endpoint
- [x] Register `PublicationService.dispatch` as a named action (`besluitvormingPublish`) in the workflow engine action registry for the "Bekendmaking" step
- [x] Skip Bekendmaking for Mandaatbesluit (template routes Besluit genomen → Gearchiveerd directly; `dispatch()` also skips when caseType.publicationRequired is false)

### Task 9: Build BesluitPublicatiePanel.vue
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-006`
- **files**: `src/components/besluitvorming/BesluitPublicatiePanel.vue`
- **acceptance_criteria**:
  - Shows publication status (pending / success / failed) with publication reference URI on success
  - Shows error message and "Opnieuw proberen" button on failure
  - On success, shows deep link to the DROP/LVBB publication
- [x] Build panel component with publication status states
- [x] Wire retry button to `POST /api/besluitvorming/cases/{id}/publish`
- [x] Register panel in `registry.js` for embedding (DEFERRED: slotting it into the existing CaseDetail sidebar-tab config — component is ready and registered)

## 6. Mandaatregister Validation

### Task 10: Implement MandaatValidationService
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-007`
- **files**: `lib/Service/MandaatValidationService.php`, `lib/Controller/BesluitvormingController.php`, `lib/Service/Transitions/MandaatGuard.php`
- **acceptance_criteria**:
  - GIVEN valid mandate WHEN validate(caseId, signingUserId) called THEN returns true and workflow proceeds
  - GIVEN insufficient mandate THEN returns false with descriptive error; transition blocked
  - GIVEN mandaatregister unreachable THEN handler prompted for manual confirmation; confirmation logged in audit trail
- [x] Implement `validate(string $caseId, string $signingUserId)` — queries the configured mandaatregister URL
- [x] Handle endpoint unavailability — return `requiresManualConfirmation` flag (never a silent pass)
- [x] Register `GET /api/besluitvorming/cases/{id}/mandaat-check` endpoint
- [x] Register the mandaat validation as a `mandaatGuard` evaluator used by the Mandaatbesluit template transition
- [x] Surface validation error and mandaatregister link in the case detail UI (guard returns `registerLink` + message; `mandaatCheck` endpoint exposes it)

## 7. Archival Guard

### Task 11: Add archival document guard to workflow template
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-008`
- **files**: `lib/Settings/templates/bvw-college-besluit.json`, `lib/Settings/templates/bvw-raadsbesluit.json`
- **acceptance_criteria**:
  - GIVEN case missing besluitdocument WHEN handler tries to advance to Gearchiveerd THEN transition blocked with list of missing documents
  - GIVEN all required documents present WHEN transition triggered THEN case.archiveStatus set, archiveActionDate computed from resultType.archivalPeriod
- [x] Add `requiredDocument` guards to the "Gearchiveerd" transition in College-besluit and Raadsbesluit templates (Collegeadvies/Raadsvoorstel, Besluitdocument, Bekendmakingsbewijs)
- [x] Add `setField` auto-action on "Gearchiveerd" transition to set `archiveStatus = 'gearchiveerd'`
- [x] Configure `resultType.archivalPeriod = 'P20Y'` and `archivalAction = 'keep'` for "Besluit genomen" result type

## 8. Frontend Integration and i18n

### Task 12: Add Besluitvorming section to Procest navigation and i18n
- **files**: `src/manifest.d/50-besluitvorming.json`, `src/registry.js`, `l10n/nl.json`, `l10n/en.json`, `l10n/nl.js`, `l10n/en.js`
- **acceptance_criteria**:
  - "Besluitvorming" navigation item appears in sidebar for users with the Behandelaar or Agendabeheerder role
  - All new UI strings have Dutch (primary) and English translations
  - AgendaCompilerView, VergaderingDetailView, and BesluitPublicatiePanel are all accessible via the v2 manifest renderer
- [x] Add "Besluitvorming" navigation entry to sidebar (manifest.d fragment — declarative manifest-v2, no `src/router/index.js`)
- [x] Register routes/pages for AgendaCompilerView, VergaderingDetailView (manifest pages + registry.js components)
- [x] Add all Dutch and English i18n strings for new components (nl + en, both `.json` and `.js`)
- [x] Add role-based navigation visibility (`requiresRole: [Behandelaar, Agendabeheerder, Griffier]` on the menu entry)
