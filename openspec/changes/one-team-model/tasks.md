# Tasks: one-team-model

Kind: code. Based on `development`.

## 1. Register

- [x] 1.1 `case.assignedGroup`: `referenceType: nextcloud-group`, no `$ref`, no
  `format`; case schema 1.38.0; register 0.20.21. Live and mock register.
  - `tests/Unit/Settings/RegisterSchemaGroupFieldsTest.php`
- [x] 1.2 `organisatieRol.ncGroupId` (61-mandaat-matrix.json, schema 1.3.0).
- [x] 1.3 Case lookup reference `team` and the guarded `assignedGroupPublicName`
  calculation.
- [x] 1.4 `assigneeNarrowing.allowedGroups` items are Nextcloud groups
  (37-intake-triage.json).
- [x] 1.5 Demo cases name a group instead of an `@ref` to a role
  (46-demo-cases-english.json).
  - `tests/Unit/Settings/MandaatMatrixSeedTest.php`

## 2. Backend

- [x] 2.1 `RefusalOutcome::teamFor()` writes the role's `ncGroupId`.
  - `tests/Unit/Service/Routing/RefusalOutcomeTest.php`
- [x] 2.2 `CaseTeamMigration` service, `MigrateCaseTeamsToGroups` repair step
  (post-migration, before `BackfillCaseCustody`), `occ dossiq:teams:migrate`
  with `--dry-run`.
  - `tests/Unit/Service/Team/CaseTeamMigrationTest.php`
  - `tests/Unit/Repair/RepairStepRegistrationTest.php`
- [x] 2.3 Docblocks that still call `assignedGroup` a uuid reference.

## 3. Frontend

- [x] 3.1 Cases index Team column reads `assignedGroup`; drop `extend`.
  - `tests/vitest/casePartiesWidget.spec.js`
- [x] 3.2 e2e fixtures seed a Nextcloud group, not an organisation role.
  - `tests/e2e/case-parties.spec.ts`, `tests/e2e/custody-and-handover-of-a-case.spec.ts`,
    `tests/e2e/helpers/fixtures.ts`

## 4. Verification

- [x] 4.1 `composer check:strict`, `npm run lint`, `npm run test:l10n`.
