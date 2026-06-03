# WOO Case Type Specification

## Problem

WOO (Wet open overheid) requests are one of the most common, deadline-driven case types Dutch municipalities must handle, yet Procest currently ships no pre-configured WOO zaaktype. Case workers create WOO cases ad hoc, leading to inconsistent stage names, missed 4-week deadlines, undocumented weigeringsgronden, and unredacted personal data being disclosed. The statutory 8-stage WOO lifecycle (ontvangst → beoordeling ontvankelijkheid → zoeken documenten → beoordelen documenten → lakken/anonimiseren → besluit → publicatie → afgehandeld) is complex and requires enforcement at every stage to ensure compliance with Wet open overheid (2022) and Awb Art. 6:7 (bezwaar).

## Proposed Solution

Ship a ready-to-activate WOO zaaktype template plus the supporting services and UI components needed to run the statutory WOO lifecycle out of the box. The implementation uses the existing case engine (case, statusType, documentType, decision objects from OpenRegister) with domain services for the parts that go beyond plain case management: deadline enforcement, per-document assessment, and optional Docudesk-driven redaction.

## Scope

This change covers:
1. WOO zaaktype template JSON with 8 ordered statuses and full property/document/decision definitions
2. TemplateLibraryService and TemplateLibraryController for template activation
3. WOODeadlineService enforcing the 4-week deadline with optional 2-week extension
4. WOODocumentAssessmentService for per-document classification (openbaar/deels openbaar/niet openbaar)
5. Vue components: WOOIntakeForm, DocumentAssessmentTable, TemplateLibrary admin tab
6. Optional Docudesk integration hook for the redaction stage
7. API endpoints for template listing, activation, assessment, and deadline extension

Out of scope: automated PLOOI publication, bezwaar workflow (separate change), inventarislijst PDF generation, Mijn Overheid notification integration.

## Success Criteria

- Admin can activate the WOO zaaktype template via the template library UI
- Case workers can create WOO cases and progress through the 8-stage lifecycle
- 4-week processing deadline is enforced with warnings at T-7 days
- Optional 2-week extension can be applied once per case
- All collected documents must be assessed before advancing to redaction stage
- Assessment includes classification (openbaar/deels openbaar/niet openbaar) and weigeringsgrond selection
- Decision object references all assessments and weigeringsgronden
- Optional Docudesk integration triggers on "Lakken" stage for deels-openbaar documents
- Fallback manual redaction flow available when Docudesk is not installed
