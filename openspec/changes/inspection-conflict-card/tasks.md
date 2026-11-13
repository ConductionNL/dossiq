# Tasks: inspection-conflict-card

### Task 1: Conflict card in the sync frame
- **spec_ref**: `openspec/changes/inspection-conflict-card/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits`
- **files**: `src/manifest.d/72-field-inspection.json`, `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`
- [x] Place `inspection-conflict` (`conflict-resolve`) in the sync frame under the outbox
- [x] The board's texts in English with Dutch translations
- [x] Buttons wait for the capability `dossiq.conflictResolution`
- [x] Test: `tests/vitest/inspectionConflictCard.spec.js`

### Task 2: conflictRecord carries its target and fields
- **spec_ref**: `openspec/changes/inspection-conflict-card/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits`
- **files**: `lib/Settings/register.d/40-mobiel-inspectie-offline.json`, `tests/schemas/schema-version-digests.json`
- [x] Declare `targetRegister`, `targetSchema`, `targetId`, `conflictFields`, `detectedAt`; version 1.1.0
- [x] Schema strings in the catalogues

### Task 3: The board's conflict in the example set
- **files**: `lib/Settings/example-sets/zuiddrecht.d/64-inspection-conflict.json`
- [x] One open conflict linked to `outbox-molenweg-bevinding`, with both texts at `responses.0.comment`

### Task 4: Resolve endpoint (SIG)
- **spec_ref**: `openspec/changes/inspection-conflict-card/specs/mobiel-inspectie-offline/spec.md#requirement-resolving-a-conflict-record-writes-the-chosen-value-and-marks-the-record-resolved`
- **files**: `appinfo/routes.php`, `lib/Controller/ConflictRecordController.php`, `lib/Service/...`, `lib/Capabilities.php`, `lib/AppInfo/Application.php`
- [x] Route, controller and service per the contract in `design.md`, access checked before any write
- [x] Capability `conflictResolution`
- [x] PHPUnit: refused resolution, already resolved, office changed again, no update right, both choices

### Task 5: File conflicts from the device (after Task 4)
- **files**: `src/main.js`, nextcloud-vue `src/integrations/offline/offlineDb.js`
- [ ] Open decision 4 in `design.md`
