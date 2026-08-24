# Tasks: migrate-committees-to-decidiq

> ⛔ BLOCKED on decidiq#874 merging and reaching `development`. The target fields
> and the cross-app write path do not exist before it.

## Implementation Tasks

### Task 1: The migration repair step
- **spec_ref**: `openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md#requirement-req-mcd-001-committees-migrate-to-governance-bodies`
- **files**: `lib/Repair/MigrateCommitteesToDecidiq.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN committees WHEN the step runs THEN each yields a GovernanceBody with the mapped fields and `bodyType: advisory-body`
  - GIVEN a second run THEN nothing is created
  - GIVEN no session THEN the writes still succeed (runAsSystem), and a failure to obtain one FAILS rather than warning
  - GIVEN the test fake THEN it implements `runAsSystem()`, so removing the wrapper breaks the suite
- [ ] Implement
- [ ] Test

### Task 2: Roster fan-out
- **spec_ref**: `...#requirement-req-mcd-002-the-roster-fans-out-to-memberships`
- **files**: `lib/Repair/MigrateCommitteesToDecidiq.php`
- **acceptance_criteria**:
  - GIVEN a committee with N members WHEN migrated THEN N Memberships exist on the new body with correct roles
  - GIVEN a re-run THEN no duplicates
- [ ] Implement
- [ ] Test

### Task 3: Read path with a permanent fallback
- **spec_ref**: `...#requirement-req-mcd-003-reads-resolve-from-decidiq-falling-back-locally`
- **files**: `lib/Service/Bezwaar/AdvisoryCommitteeService.php`, `lib/Service/Bezwaar/PanelIndependenceChecker.php`, `lib/Listener/BezwaarAdviceRequestedListener.php`, `lib/Service/Bezwaar/BezwaarAuditTrail.php`, `lib/Service/SettingsService.php`, `lib/Service/Settings/SchemaSlugMap.php`
- **acceptance_criteria**:
  - GIVEN decidiq absent THEN reads fall back locally with no error
  - GIVEN a migrated committee with `active: false` THEN the archive refusal still fires
- [ ] Implement
- [ ] Test

### Task 4: Retire the local schema
- **spec_ref**: all
- **acceptance_criteria**:
  - Deferred until the fallback is provably unreachable on supported installs. Retiring it while any install still reads it removes a working feature.
- [ ] Implement
- [ ] Test
