# Proposal: woo-case-type

## Placement & Information Architecture

**Placement type:** `ACTION` — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** Configuratie › Zaaktypes

**Rationale:** WOO-zaaktype-seed.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Why

WOO (Wet open overheid) requests are one of the most common, deadline-driven case types Dutch municipalities must handle, yet Procest currently ships no pre-configured WOO zaaktype. Case workers create WOO cases ad hoc, leading to inconsistent stage names, missed 4-week deadlines, undocumented weigeringsgronden, and unredacted personal data being disclosed. This change ships a ready-to-activate WOO zaaktype template plus the supporting UI and services needed to run the statutory 8-stage WOO lifecycle out of the box.

## What Changes

1. WOO zaaktype template JSON (`lib/Settings/templates/woo-verzoek.json`) with 8 ordered statuses, property definitions, document types, decision types, role types, and version metadata.
2. `TemplateLibraryService` and `TemplateLibraryController` to load, preview, and activate zaaktype templates into the active register; same mechanism reusable by other domain templates.
3. `WOODeadlineService` enforcing the 4-week response deadline with a single optional 2-week extension per WOO Art. 4.4.
4. `WOODocumentAssessmentService` for per-document classification (openbaar/deels openbaar/niet openbaar) with weigeringsgrond selection from WOO Art. 5.1/5.2.
5. Vue components: `WOOIntakeForm`, `DocumentAssessmentTable`, `TemplateLibrary` admin tab.
6. Optional Docudesk integration hook for the redaction (lakken) stage.

## Impact

- **Affected projects**: procest (primary), docudesk (optional integration), openregister (template storage).
- **Code surface**: 1 service group, 1 controller, 3 Vue components, 1 JSON template, route additions, 1 repair-step registration.
- **APIs**: `GET /api/templates`, `POST /api/templates/{id}/activate`, `POST /api/cases/{id}/woo/assessment`, `POST /api/cases/{id}/woo/extend-deadline`.
- **Dependencies**: OpenRegister, Docudesk (optional), Mijn Overheid (notification — out of scope this change).
- **Standards**: Wet open overheid (2022), Awb Art. 6:7 (bezwaar), PLOOI publication, AVG/GDPR.



## Design

# Design: woo-case-type

## Architecture

WOO is implemented as a domain template on top of the existing Procest case engine, not as a fork. A JSON template file describes the zaaktype (8 statuses, property defs, document types, role types, decision types). A `TemplateLibraryService` activates that template into the active register, materialising it as standard OpenRegister objects. Domain services handle the parts that go beyond plain case-management: WOO deadlines, per-document assessment, and (optional) Docudesk-driven redaction.

### Service Layout

- `TemplateLibraryService` — `listTemplates()`, `previewTemplate(id)`, `activateTemplate(id, overrides)`; reads from `lib/Settings/templates/*.json`; reusable for non-WOO templates.
- `WOODeadlineService` — calculates `expectedResolution` as receipt + 4 weeks; supports a single `extendDeadline(reason)` of up to 2 weeks; emits warnings at T-7 and overdue events.
- `WOODocumentAssessmentService` — CRUD on per-document `wooAssessment` records (openbaar/deels openbaar/niet openbaar + weigeringsgrond[] + motivering); guards stage advancement until every collected document is assessed.
- `WOODecisionService` — assembles the formal besluit referencing all assessments and weigeringsgronden; writes a `decision` object linked to the case.

### Data Model

Most data lives as standard `case`, `statusType`, `documentType`, `decision` objects produced by the template. WOO-specific extensions:

- `wooAssessment` schema (added to `procest_register.json`): caseRef, documentRef, classification (enum), weigeringsgronden (array of WOO Art. 5.1/5.2 codes), motivering (text), assessedBy, assessedAt.
- Property definitions on the WOO zaaktype: verzoeker_naam, verzoeker_type, onderwerp, periode_van, periode_tot, bestuurlijke_aangelegenheid, kanaal, deadline_verlengd.

### 8-Stage Lifecycle

1. Ontvangst → 2. Beoordeling ontvankelijkheid → 3. Zoeken documenten → 4. Beoordelen documenten → 5. Lakken / Anonimiseren → 6. Besluit → 7. Publicatie → 8. Afgehandeld.

Each transition is enforced by the case engine; stages 4→5 require all `wooAssessment` records present; stage 6 requires every assessment to have a classification + (where needed) weigeringsgrond.

### API Surface

- `GET /api/templates` — list available templates with metadata.
- `POST /api/templates/{id}/activate` — activate (optionally with field overrides).
- `POST /api/cases/{id}/woo/assessment` — bulk-upsert assessments.
- `POST /api/cases/{id}/woo/extend-deadline` — extend with reason.
- `POST /api/cases/{id}/woo/decision` — assemble the besluit object.

## Dependencies

- OpenRegister (template storage and all WOO objects).
- Docudesk (optional, for the "lakken" stage; falls back to manual redaction).
- Procest case engine (status transitions, timeline, deadline panel).
- Mijn Overheid integration (verzoeker notification — out of scope this change, hooks left).

## Out of Scope

- Automated PLOOI publication.
- Bezwaar workflow (handled by `bezwaar-beroep-workflow`).
- Inventarislijst PDF generation (separate doc-gen change).
- Berichtenbox push notifications (separate Mijn Overheid integration change).



## Tasks

# Tasks: woo-case-type

## 1. Template Library

### Task 1: Create TemplateLibraryService
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-1-woo-zaaktype-template-activation`
- **files**: `lib/Service/TemplateLibraryService.php`, `lib/Controller/TemplateLibraryController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a `woo-verzoek.json` template WHEN admin POSTs `/api/templates/woo-verzoek/activate` THEN a zaaktype with 8 statuses is created in the active register
  - GIVEN re-activation of the same template WHEN POSTed THEN existing zaaktype is updated, not duplicated
  - GIVEN field overrides in the payload WHEN activated THEN overrides applied while base template stays untouched
- [ ] Implement listTemplates(), previewTemplate(), activateTemplate()
- [ ] Implement TemplateLibraryController
- [ ] Register routes

### Task 2: Ship WOO zaaktype template JSON
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-2-woo-lifecycle-stages`
- **files**: `lib/Settings/templates/woo-verzoek.json`
- **acceptance_criteria**:
  - Template contains 8 ordered status types matching the WOO lifecycle
  - Template includes WOO intake property definitions, document types, role types, decision types, and version metadata
- [ ] Author template JSON with full 8-stage lifecycle
- [ ] Validate template via `jq` and OpenRegister importer dry run

## 2. Intake and Lifecycle

### Task 3: Build WOOIntakeForm
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-3-woo-specific-intake-form`
- **files**: `src/views/cases/components/WOOIntakeForm.vue`
- **acceptance_criteria**:
  - Form renders verzoeker_naam, contactgegevens, type, onderwerp, periode_van/tot, bestuurlijke_aangelegenheid, kanaal
  - Required-field validation blocks save until satisfied
- [ ] Build Vue form bound to property definitions on the WOO zaaktype

### Task 4: Implement WOODeadlineService
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-4-woo-deadline-tracking-and-extension`
- **files**: `lib/Service/WOODeadlineService.php`
- **acceptance_criteria**:
  - GIVEN a WOO case created on 2026-05-01 WHEN deadline read THEN expectedResolution = 2026-05-29 (28 days)
  - GIVEN one extension applied with reason WHEN attempting a second extension THEN error returned
  - GIVEN deadline-7 reached WHEN nightly job runs THEN warning notification sent to behandelaar
- [ ] Implement calculate(), extendDeadline(), checkAndWarn()
- [ ] Wire into existing DeadlinePanel.vue

## 3. Document Assessment and Decision

### Task 5: Implement WOODocumentAssessmentService
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-6-per-document-disclosure-assessment`
- **files**: `lib/Service/WOODocumentAssessmentService.php`, `lib/Controller/WOOAssessmentController.php`
- **acceptance_criteria**:
  - GIVEN 12 collected documents WHEN bulk-assess called with 11 classifications THEN advancing to "Lakken" is blocked with "Document 12 needs assessment"
  - GIVEN classification "deels openbaar" without weigeringsgrond WHEN saved THEN validation error returned
- [ ] Implement bulkUpsert(), validate(), getOutstanding()
- [ ] Implement controller endpoint

### Task 6: Build DocumentAssessmentTable
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-6-per-document-disclosure-assessment`
- **files**: `src/views/cases/components/DocumentAssessmentTable.vue`
- **acceptance_criteria**:
  - Table renders one row per collected document with classification select and weigeringsgrond multi-select
  - Saving the table calls the bulkUpsert endpoint
- [ ] Build Vue table component

### Task 7: Implement WOODecisionService and besluit flow
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-8-woo-decision-besluit`
- **files**: `lib/Service/WOODecisionService.php`
- **acceptance_criteria**:
  - GIVEN every document assessed WHEN decision created THEN a `decision` object is linked to the case referencing all assessments
  - GIVEN unassessed document WHEN decision attempted THEN blocked with explicit error
- [ ] Implement assembleDecision()
- [ ] Wire into controller and besluit panel

## 4. Optional Docudesk Integration

### Task 8: Wire Docudesk redaction hook on "Lakken" stage
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-7-redaction-via-docudesk-integration`
- **files**: `lib/Service/WOORedactionService.php`
- **acceptance_criteria**:
  - GIVEN Docudesk is installed AND case enters "Lakken / Anonimiseren" WHEN documents are flagged "deels openbaar" THEN they are queued in Docudesk for redaction
  - GIVEN Docudesk is not installed WHEN stage entered THEN UI falls back to manual upload-redacted-version flow
- [ ] Implement WOORedactionService with feature detection
- [ ] Add UI fallback for non-Docudesk installs
