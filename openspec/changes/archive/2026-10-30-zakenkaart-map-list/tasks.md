# Tasks: zakenkaart-map-list

### Task 1: A map list row reads address, case number and names its type and status
- **spec_ref**: `openspec/changes/zakenkaart-map-list/specs/zakenkaart-map-list/spec.md#requirement-a-map-list-row-reads-address-case-number-and-names-its-type-and-status`
- **files**: `src/services/caseAddress.js`, `src/services/formatters.js`, `src/menu-layout.simple.json`
- [x] Implement: `caseAddress` formatter, the list's sub line and pills
- [x] Test: `tests/vitest/caseAddress.spec.js`

### Task 2: The empty locations line sits on the card's inset
- **spec_ref**: `openspec/changes/zakenkaart-map-list/specs/zakenkaart-map-list/spec.md#requirement-the-empty-locations-line-sits-on-the-cards-inset`
- **files**: `src/components/case/CaseLocationMap.vue`
- [x] Implement: no padding on the empty line, 14px
- [x] Test: `tests/vitest/caseAddress.spec.js`
