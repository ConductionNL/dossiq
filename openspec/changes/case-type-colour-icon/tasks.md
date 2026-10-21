# Tasks: case-type-colour-icon

### Task 1: A case type carries a colour and an icon
- **spec_ref**: `openspec/changes/case-type-colour-icon/specs/case-types/spec.md#requirement-a-case-type-carries-a-colour-and-an-icon`
- **files**: `lib/Settings/register.d/99-case-type-palette.json`, `lib/Settings/dossiq_register.json`, the shipped case type rows, `src/utils/statusColour.js`, `src/manifest.json`, `src/menu-layout.simple.json`, `lib/Service/CaseTypeViews.php`, `lib/Service/MenuCaseTypesService.php`
- [x] Implement: schema, palette with teal, shipped colours and icons, form and card, pills and tags, menu icons
- [x] Test: `tests/Unit/Settings/CaseTypeColourIconTest.php`, `tests/vitest/caseTypeColourIcon.spec.js`, `tests/vitest/statusColour.spec.js`
