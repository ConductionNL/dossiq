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

### Components

| Component | Path | Purpose |
|-----------|------|---------|
| WOOIntakeForm.vue | `src/views/cases/components/WOOIntakeForm.vue` | Intake form for WOO case creation (verzoeker name, type, subject, period, channel) |
| DocumentAssessmentTable.vue | `src/views/cases/components/DocumentAssessmentTable.vue` | Per-document classification and weigeringsgrond selection table |
| TemplateLibrary.vue | `src/views/settings/TemplateLibrary.vue` | Admin tab for template preview and activation |
| TemplateLibraryService.php | `lib/Service/TemplateLibraryService.php` | Template loading, preview, and activation |
| TemplateLibraryController.php | `lib/Controller/TemplateLibraryController.php` | API endpoints for templates |
| WOODeadlineService.php | `lib/Service/WOODeadlineService.php` | Deadline calculation and extension |
| WOODocumentAssessmentService.php | `lib/Service/WOODocumentAssessmentService.php` | Assessment CRUD and validation |
| WOOAssessmentController.php | `lib/Controller/WOOAssessmentController.php` | API endpoints for assessments |
| WOODecisionService.php | `lib/Service/WOODecisionService.php` | Decision assembly from assessments |
| WOORedactionService.php | `lib/Service/WOORedactionService.php` | (Optional) Docudesk integration hook |

### Seed Data

WOO zaaktype template includes:
- **Catalogus**: domein "WOO", RSIN from config
- **Case type**: "WOO-verzoek" with 8 statuses, properties, document types, role types, decision type
- **Status types** (8 in order):
  1. Ontvangst (order: 1)
  2. Beoordeling ontvankelijkheid (order: 2)
  3. Zoeken documenten (order: 3)
  4. Beoordelen documenten (order: 4)
  5. Lakken / Anonimiseren (order: 5)
  6. Besluit (order: 6)
  7. Publicatie (order: 7)
  8. Afgehandeld (order: 8, isFinal: true)
- **Property definitions** (sample Dutch values):
  - verzoeker_naam (string, required)
  - verzoeker_type (enum: particulier/organisatie, required)
  - onderwerp (string, required)
  - periode_van (date)
  - periode_tot (date)
  - bestuurlijke_aangelegenheid (string)
  - kanaal (enum: post/email/portal, required)
  - deadline_verlengd (boolean)
- **Document types** (all non-required, optional categories):
  - Ingekomen verzoek
  - Relevante stukken
  - Conceptbesluit
  - Redactieverzoek
  - Gelakt document
  - Formeel besluit
- **Role type**:
  - Behandelaar (primary handler)
  - Adviseur (optional advisor)
  - Redacteur (redaction specialist, only in "Lakken" stage)
- **Decision type**:
  - WOO-besluit (publication required)

## Dependencies

- **OpenRegister** (required) — template storage and all WOO objects
- **Docudesk** (optional) — for the "lakken" stage; falls back to manual redaction
- **Procest case engine** (required) — status transitions, timeline, deadline panel
- **Mijn Overheid integration** (out of scope this change) — verzoeker notification; hooks left for future integration

## Out of Scope

- Automated PLOOI publication.
- Bezwaar workflow (handled by `bezwaar-beroep-workflow`).
- Inventarislijst PDF generation (separate doc-gen change).
- Berichtenbox push notifications (separate Mijn Overheid integration change).
