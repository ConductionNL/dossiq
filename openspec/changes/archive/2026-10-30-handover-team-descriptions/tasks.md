# Tasks: handover-team-descriptions

### Task 1: The hand-over list reads a team's description from its organisation role
- **spec_ref**: `openspec/changes/handover-team-descriptions/specs/case-management/spec.md#requirement-the-hand-over-list-reads-a-teams-description-from-its-organisation-role`
- **files**: `lib/Settings/register.d/97-organisation-role-description.json`, `src/manifest.json`, `tests/schemas/schema-version-digests.json`
- [x] Implement: optional description, describe config
- [x] Test: `tests/vitest/caseHandOverDialogBoard.spec.js`, `tests/Unit/Settings/SchemaVersionFloorTest.php`
