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
