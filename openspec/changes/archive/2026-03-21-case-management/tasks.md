# Tasks: Case Management

## Task 1: Case CRUD with case type selection [MVP] [DONE]
- **spec_ref**: case-management/spec.md#REQ-CM-01
- **files**: `src/views/cases/CaseCreateDialog.vue`, `src/views/cases/CaseList.vue`
- **acceptance**: Create, view, edit cases with case type selection

## Task 2: Case detail view with status timeline [MVP] [DONE]
- **spec_ref**: case-management/spec.md#REQ-CM-06
- **files**: `src/views/cases/CaseDetail.vue`, `src/views/cases/components/StatusTimeline.vue`
- **acceptance**: Case detail shows status timeline with transitions

## Task 3: Deadline tracking and overdue alerts [MVP] [DONE]
- **spec_ref**: case-management/spec.md
- **files**: `src/views/cases/components/DeadlinePanel.vue`, `src/utils/caseHelpers.js`
- **acceptance**: Deadlines auto-calculated, overdue cases highlighted

## Task 4: Participant management [MVP] [DONE]
- **spec_ref**: case-management/spec.md
- **files**: `src/views/cases/components/ParticipantsSection.vue`, `src/views/cases/components/AddParticipantDialog.vue`
- **acceptance**: Add/remove participants with role assignment

## Task 5: Case validation [MVP] [DONE]
- **spec_ref**: case-management/spec.md
- **files**: `src/utils/caseValidation.js`
- **acceptance**: Title required, case type required, draft/expired type rejected

## Task 6: Unit tests (ADR-009) [DONE]
- **spec_ref**: ADR-009
- **files**: `tests/Unit/`
- **acceptance**: Case management tests pass

## Task 7: Documentation and screenshots (ADR-010) [DONE]
- **spec_ref**: ADR-010
- **files**: `docs/features/case-management.md`, `docs/screenshots/dashboard.png`
- **acceptance**: Case management documented with screenshots

## Task 8: i18n support (ADR-005) [DONE]
- **spec_ref**: ADR-005
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance**: All case management strings in English and Dutch
