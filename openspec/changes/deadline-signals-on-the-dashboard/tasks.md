# Tasks: deadline-signals-on-the-dashboard

### Task 1: The case knows when its last quarter starts
- **spec_ref**: `openspec/changes/deadline-signals-on-the-dashboard/specs/signalering-widgets/spec.md#requirement-one-deadlines-table-replaces-the-overdue-and-deadline-alert-tiles-req-sig-004`
- **files**: `lib/Settings/register.d/99-case-term-signal-from.json`, `tests/schemas/schema-version-digests.json`
- [x] Implement
- [x] Test: `tests/vitest/dashboardWorkTables.spec.js`, `tests/Unit/Settings/SchemaVersionFloorTest.php`

### Task 2: The dashboard card reads Termijnsignalen
- **spec_ref**: `openspec/changes/deadline-signals-on-the-dashboard/specs/signalering-widgets/spec.md#requirement-one-deadlines-table-replaces-the-overdue-and-deadline-alert-tiles-req-sig-004`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement
- [x] Test: `tests/vitest/dashboardWorkTables.spec.js`, `tests/vitest/caseListLenses.spec.js`, `tests/e2e/dashboard-tiles.spec.ts`
