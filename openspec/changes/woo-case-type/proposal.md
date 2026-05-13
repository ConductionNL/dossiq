# Proposal: woo-case-type

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
