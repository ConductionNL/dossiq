# Tasks: status-type-board-colours

### Task 1: A shipped status takes the colour its boards draw
- **spec_ref**: `openspec/changes/status-type-board-colours/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw`
- **files**: `lib/Service/StatusTypePalette.php`, `lib/Repair/RecolourShippedStatusTypes.php`, `appinfo/info.xml`, the shipped status rows, the six package manifests, `lib/Settings/dossiq_register.json`
- [x] Implement: palette, seeds, the recolour step, version moves
- [x] Test: `tests/Unit/Service/StatusTypePaletteTest.php`, `tests/Unit/Repair/RecolourShippedStatusTypesTest.php`, `tests/vitest/statusTypeBoardColours.spec.js`
