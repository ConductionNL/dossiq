# Tasks: Admin Settings

## Task 1: Admin settings page and case type CRUD [MVP] [DONE]
- **spec_ref**: admin-settings/spec.md
- **files**: `src/views/settings/AdminRoot.vue`, `src/views/settings/CaseTypeList.vue`, `src/views/settings/CaseTypeDetail.vue`, `lib/Controller/SettingsController.php`, `lib/Service/SettingsService.php`
- **acceptance**: Admin can view, create, edit, delete case types from settings page

## Task 2: Status type management tab [MVP] [DONE]
- **spec_ref**: admin-settings/spec.md
- **files**: `src/views/settings/tabs/StatusesTab.vue`
- **acceptance**: Admin can manage status types within a case type

## Task 3: ZGW mapping settings [MVP] [DONE]
- **spec_ref**: admin-settings/spec.md
- **files**: `src/views/settings/ZgwMappingSettings.vue`, `lib/Controller/ZgwMappingController.php`, `lib/Service/ZgwMappingService.php`
- **acceptance**: Admin can view and edit ZGW field mappings

## Task 4: Unit tests (ADR-009) [DONE]
- **spec_ref**: ADR-009
- **files**: `tests/Unit/Service/SettingsServiceTest.php`
- **acceptance**: SettingsService unit tests pass

## Task 5: Documentation and screenshots (ADR-010) [DONE]
- **spec_ref**: ADR-010
- **files**: `docs/features/administration.md`, `docs/screenshots/dashboard.png`
- **acceptance**: Feature documentation exists with screenshots

## Task 6: i18n support (ADR-005) [DONE]
- **spec_ref**: ADR-005
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance**: All admin settings strings available in English and Dutch
