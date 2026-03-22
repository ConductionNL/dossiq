# Tasks: OpenRegister Integration

## Task 1: Register and schema initialization [MVP] [DONE]
- **spec_ref**: openregister-integration/spec.md
- **files**: `lib/Service/SettingsService.php`, `lib/Repair/`
- **acceptance**: Register and schemas created on app enable

## Task 2: Pinia object store integration [MVP] [DONE]
- **spec_ref**: openregister-integration/spec.md
- **files**: `src/store/modules/object.js`
- **acceptance**: CRUD operations work via OpenRegister API

## Task 3: Settings configuration UI [MVP] [DONE]
- **spec_ref**: openregister-integration/spec.md
- **files**: `src/views/settings/Settings.vue`, `lib/Controller/SettingsController.php`
- **acceptance**: Admin can configure register/schema IDs

## Task 4: Unit tests (ADR-009) [DONE]
- **spec_ref**: ADR-009
- **files**: `tests/Unit/Service/SettingsServiceTest.php`
- **acceptance**: Settings service tests pass

## Task 5: Documentation (ADR-010) [DONE]
- **spec_ref**: ADR-010
- **files**: `docs/ARCHITECTURE.md`
- **acceptance**: OpenRegister integration architecture documented

## Task 6: i18n support (ADR-005) [DONE]
- **spec_ref**: ADR-005
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance**: Integration strings in English and Dutch
