# Tasks: case-macros-per-type

### Task 1: A case type lists its macros
- **spec_ref**: `openspec/changes/case-macros-per-type/specs/case-dashboard-view/spec.md#requirement-a-case-type-chooses-the-macros-its-cases-offer`
- **files**: `lib/Settings/register.d/99-case-type-which-macros.json`, `lib/Settings/register.d/81-woo-verzoek.json`, `src/menu-layout.simple.json`, `l10n/*`
- [x] Implement: the property, the Woo list, `macrosFrom` on the card
- [x] Test: `tests/Unit/Flow/CaseMacroDeclarationTest.php`, `tests/vitest/simpleCasePage.spec.js`
