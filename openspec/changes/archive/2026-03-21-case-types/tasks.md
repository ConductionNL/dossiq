# Tasks: Case Types

## Task 1: Case type data model [MVP] [DONE]
- **spec_ref**: case-types/spec.md
- **files**: OpenRegister schema definitions
- **acceptance**: CaseType entity with all fields stored in OpenRegister

## Task 2: Case type CRUD UI [MVP] [DONE]
- **spec_ref**: case-types/spec.md
- **files**: `src/views/settings/CaseTypeDetail.vue`, `src/views/settings/CaseTypeList.vue`
- **acceptance**: Admin can create, edit, view, delete case types

## Task 3: Status type management [MVP] [DONE]
- **spec_ref**: case-types/spec.md
- **files**: `src/views/settings/tabs/StatusesTab.vue`
- **acceptance**: Status types with ordering and isFinal flag

## Task 4: Case type validation [MVP] [DONE]
- **spec_ref**: case-types/spec.md
- **files**: `src/utils/caseTypeValidation.js`
- **acceptance**: Validation rules enforced on case type changes

## Task 5: Unit tests (ADR-009) [DONE]
- **spec_ref**: ADR-009
- **acceptance**: Case type tests pass

## Task 6: Documentation and screenshots (ADR-010) [DONE]
- **spec_ref**: ADR-010
- **files**: `docs/features/case-types.md`
- **acceptance**: Case types documented

## Task 7: i18n support (ADR-005) [DONE]
- **spec_ref**: ADR-005
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance**: Case type strings in English and Dutch
