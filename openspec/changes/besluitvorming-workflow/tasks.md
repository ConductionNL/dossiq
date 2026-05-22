# Tasks: besluitvorming-workflow

## 1. Zaaktype Templates and Seed Data

### Task 1: Author besluitvorming template JSON files
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-001`
- **files**: `lib/Settings/templates/bvw-college-besluit.json`, `lib/Settings/templates/bvw-raadsbesluit.json`, `lib/Settings/templates/bvw-mandaatbesluit.json`
- **acceptance_criteria**:
  - GIVEN admin activates "college-besluit" template WHEN repair step runs THEN caseType, workflowTemplate, 9 statusTypes, 5 propertyDefinitions, 4 roleTypes, 3 documentTypes, and 3 resultTypes are created
  - Activation is idempotent (re-run does not duplicate records)
- [ ] Author `bvw-college-besluit.json` with full 9-step lifecycle
- [ ] Author `bvw-raadsbesluit.json` including Griffier role and P60D deadline
- [ ] Author `bvw-mandaatbesluit.json` with `confidentiality = 'intern'` and mandate guard flag
- [ ] Include default `parafeerroute` records for each template (3-step, 4-step, 2-step)

### Task 2: Create BesluitvormingTemplateService and repair step
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-001`
- **files**: `lib/Service/BesluitvormingTemplateService.php`, `lib/Migration/RepairStep/SeedBesluitvormingTemplates.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN fresh install WHEN repair step runs THEN all three template bundles are seeded
  - POST /api/besluitvorming/templates/{slug}/activate re-seeds a single template on demand
- [ ] Implement `BesluitvormingTemplateService.activate(string $slug)` — reads template JSON and upserts to OpenRegister
- [ ] Implement repair step that calls `activate()` for all three templates
- [ ] Register `POST /api/besluitvorming/templates/{slug}/activate` route
- [ ] Register route and controller method

## 2. Parafering Chain Orchestration

### Task 3: Implement BesluitvormingParafeerService
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-002`
- **files**: `lib/Service/BesluitvormingParafeerService.php`
- **acceptance_criteria**:
  - GIVEN voorstel submitted WHEN service.activate() called THEN routeSnapshot populated, currentStep = 1, task created for step-1 parafeerder
  - GIVEN parafeeractie goedgekeurd at step N WHEN handleParaafAction() called THEN step-N+1 task created; if N = final step, transitions case to "Gereed voor agendering"
  - GIVEN retour action WHEN handleParaafAction() called THEN voorstel.status = 'retour', returnedFromStep set, steller notified
- [ ] Implement `activate(string $voorstelId)` — snapshot route, set currentStep, create first task, notify parafeerder
- [ ] Implement `handleParaafAction(string $voorstelId, string $parafeeractieId)` — advance chain or mark retour
- [ ] Implement delegation handling: validate `actorType = 'gemachtigde'` with `onBehalfOf` and `mandate` fields
- [ ] Implement `checkAllParafenCollected(string $voorstelId)` — skip optional steps, detect completion
- [ ] Emit case status transition to "Gereed voor agendering" on chain completion via WorkflowEngine

### Task 4: Wire parafering service to workflow engine auto-action
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-002`
- **files**: `lib/Service/WorkflowActionHandler.php` (or equivalent hook point in workflow-engine-enhancement)
- **acceptance_criteria**:
  - GIVEN workflowTemplate step "Parafering" with automaticAction type=webhook target=BesluitvormingParafeerService.activate WHEN case reaches Parafering status THEN service is invoked automatically
- [ ] Register `BesluitvormingParafeerService.activate` as a named webhook target in the workflow engine action registry
- [ ] Ensure the auto-action is triggered on entry to the "Parafering" status step

## 3. Agenda Management

### Task 5: Implement AgendaService
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-004`
- **files**: `lib/Service/AgendaService.php`, `lib/Controller/AgendaController.php`
- **acceptance_criteria**:
  - GIVEN 4 cases with status "Gereed voor agendering" WHEN getReadyItems(vergadergremium) called THEN only cases matching that gremium are returned
  - GIVEN agenda confirmed with 6 items WHEN confirmAgenda() called THEN each case transitions to "Geagendeerd" and caseProperty.agendanummer is set
  - GIVEN agenda confirmed WHEN generateAgendaDocument() called THEN Docudesk PDF produced with hamerstukken first, then bespreekstukken
- [ ] Implement `getReadyItems(string $vergadergremium): array` — filter cases by status and caseType
- [ ] Implement `addItem(string $caseId, string $classification, int $order)` — set caseProperty values
- [ ] Implement `confirmAgenda(array $caseIds, string $meetingDate)` — transition cases to "Geagendeerd", set agendanummers
- [ ] Implement `generateAgendaDocument(array $caseIds)` — call Docudesk and link resulting document to vergadering case
- [ ] Register REST routes: `POST /api/besluitvorming/cases/{id}/agenda`, `PUT /api/besluitvorming/cases/{id}/agenda`

### Task 6: Build AgendaCompilerView.vue
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-004`
- **files**: `src/views/besluitvorming/AgendaCompilerView.vue`, `src/components/besluitvorming/AgendaItem.vue`
- **acceptance_criteria**:
  - View shows two panels: "Beschikbaar voor agendering" and "Agenda [vergaderdatum]"
  - Items can be dragged from available to agenda panel
  - Each agenda item has a Hamerstuk/Bespreekstuk toggle and can be reordered
  - "Agenda bevestigen" and "Agenda genereren" buttons call AgendaController endpoints
- [ ] Build `AgendaCompilerView.vue` with drag-and-drop between available and agenda panels
- [ ] Build `AgendaItem.vue` with hamerstuk/bespreekstuk toggle and order handle
- [ ] Wire to `AgendaController` REST endpoints
- [ ] Add route and navigation entry under Besluitvorming section

## 4. Decision Recording

### Task 7: Build VergaderingDetailView.vue
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-005`
- **files**: `src/views/besluitvorming/VergaderingDetailView.vue`
- **acceptance_criteria**:
  - Shows all geagendeerde cases for a meeting with their agendanummer and behandeling type
  - For each case: decision type picker, stemuitslag input, attending members (role assignment), explanation textarea
  - "Besluit vastleggen" creates decision object and transitions case; disabled until required fields filled
- [ ] Build form to create `decision` object with decisionDate, governingBody, decisionType, explanation
- [ ] Add `stemuitslag` input that writes to `caseProperty`
- [ ] Add attending members section that creates `role` objects with roleType "Aanwezig lid"
- [ ] Implement "Aangehouden" flow — routes case back to "Gereed voor agendering" without creating besluit
- [ ] Guard "Besluit vastleggen" button until all required fields are populated

## 5. Publication Hook

### Task 8: Implement PublicationService
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-006`
- **files**: `lib/Service/PublicationService.php`, `lib/Controller/PublicationController.php`
- **acceptance_criteria**:
  - GIVEN publicationRequired = true and signed besluitdocument present WHEN dispatch() called THEN payload assembled and sent via OpenConnector; decision.publicationDate and caseProperty.publicatieReferentie set on success
  - GIVEN endpoint unreachable WHEN dispatch() fails THEN failure logged in case activity, handler notified, retry possible via POST /api/besluitvorming/cases/{id}/publish
  - GIVEN Mandaatbesluit with publicationRequired = false WHEN workflow reaches "Besluit genomen" THEN dispatch() is NOT called and case skips Bekendmaking
- [ ] Implement `dispatch(string $caseId)` — assemble DROP/LVBB payload from decision + document fields
- [ ] Send via OpenConnector; handle success (store URI) and failure (log + notify)
- [ ] Implement `POST /api/besluitvorming/cases/{id}/publish` retry endpoint
- [ ] Register `PublicationService.dispatch` as a named webhook target in the workflow engine action registry for the "Bekendmaking" step
- [ ] Skip Bekendmaking step in template for Mandaatbesluit (route directly to Gearchiveerd)

### Task 9: Build BesluitPublicatiePanel.vue
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-006`
- **files**: `src/components/besluitvorming/BesluitPublicatiePanel.vue`
- **acceptance_criteria**:
  - Shows publication status (pending / success / failed) with publication reference URI on success
  - Shows error message and "Opnieuw proberen" button on failure
  - On success, shows deep link to the DROP/LVBB publication
- [ ] Build panel component with publication status states
- [ ] Wire retry button to `POST /api/besluitvorming/cases/{id}/publish`
- [ ] Embed panel in case detail view for cases in "Bekendmaking" status

## 6. Mandaatregister Validation

### Task 10: Implement MandaatValidationService
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-007`
- **files**: `lib/Service/MandaatValidationService.php`, `lib/Controller/MandaatController.php`
- **acceptance_criteria**:
  - GIVEN valid mandate WHEN validate(caseId, signingUserId) called THEN returns true and workflow proceeds
  - GIVEN insufficient mandate THEN returns false with descriptive error; transition blocked
  - GIVEN mandaatregister unreachable THEN handler prompted for manual confirmation; confirmation logged in audit trail
- [ ] Implement `validate(string $caseId, string $signingUserId): ValidationResult` — query configured mandaatregister URL
- [ ] Handle endpoint unavailability — return `requiresManualConfirmation` flag
- [ ] Register `GET /api/besluitvorming/cases/{id}/mandaat-check` endpoint
- [ ] Register `MandaatValidationService.validate` as a `roleGuard` in the Mandaatbesluit template transition
- [ ] Surface validation error and mandaatregister link in the case detail UI

## 7. Archival Guard

### Task 11: Add archival document guard to workflow template
- **spec_ref**: `specs/besluitvorming-workflow/spec.md#req-bvw-008`
- **files**: `lib/Settings/templates/bvw-college-besluit.json`, `lib/Settings/templates/bvw-raadsbesluit.json`
- **acceptance_criteria**:
  - GIVEN case missing besluitdocument WHEN handler tries to advance to Gearchiveerd THEN transition blocked with list of missing documents
  - GIVEN all required documents present WHEN transition triggered THEN case.archiveStatus set, archiveActionDate computed from resultType.archivalPeriod
- [ ] Add `requiredDocument` guards to the "Gearchiveerd" transition in College-besluit and Raadsbesluit templates (Collegeadvies, Besluitdocument, Bekendmakingsbewijs)
- [ ] Add `setField` auto-action on "Gearchiveerd" transition to set `archiveStatus = 'gearchiveerd'` and compute `archiveActionDate`
- [ ] Configure `resultType.archivalPeriod = 'P20Y'` and `archivalAction = 'keep'` for "Besluit genomen" result type

## 8. Frontend Integration and i18n

### Task 12: Add Besluitvorming section to Procest navigation and i18n
- **files**: `src/router/index.js`, `src/l10n/nl.json`, `src/l10n/en.json`, `src/App.vue` (navigation)
- **acceptance_criteria**:
  - "Besluitvorming" navigation item appears in sidebar for users with the Behandelaar or Agendabeheerder role
  - All new UI strings have Dutch (primary) and English translations
  - AgendaCompilerView, VergaderingDetailView, and BesluitPublicatiePanel are all accessible via the router
- [ ] Add "Besluitvorming" navigation entry to sidebar (Agenda, Vergaderingen, Besluiten sub-items)
- [ ] Register routes for AgendaCompilerView, VergaderingDetailView
- [ ] Add all Dutch and English i18n strings for new components and notification messages
- [ ] Add role-based navigation visibility guard (show only to users with Behandelaar, Agendabeheerder, or Griffier role)
