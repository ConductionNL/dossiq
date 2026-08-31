# Tasks: migrate-committees-to-decidiq

> ✅ UNBLOCKED 2026-08-30. Both prerequisites are now in place.
>
> 1. decidiq#874 — MERGED. The schema half of the target exists.
> 2. The decidiq event/listener pair — LANDED as
>    `decidiq/openspec/changes/governance-body-events`:
>    `GovernanceBodyRequestedEvent` + `GovernanceBodyRequestedListener` +
>    `GovernanceBodyCommandService`, with `GovernanceBodyCreatedEvent` carrying
>    the correlation back. The seam resolves on (sourceApp, externalReference)
>    before it writes, so the idempotency this change worried about is answered
>    on the other side rather than re-implemented here.

## Implementation Tasks

### Task 1: The migration repair step
- **spec_ref**: `openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md#requirement-req-mcd-001-committees-migrate-to-governance-bodies`
- **files**: `lib/Repair/MigrateCommitteesToDecidiq.php`, `appinfo/info.xml`
- **prerequisite**: a decidiq `GovernanceBodyRequestedEvent` + listener + a
  created-event carrying the correlation back. Mirror
  `ContractDecisionDelegationService`, which is this app's working example of
  commanding decidiq by event and correlating the answer.
- **acceptance_criteria**:
  - GIVEN committees WHEN the step runs THEN each DISPATCHES a typed event and, on the answer, a GovernanceBody exists with the mapped fields and `bodyType: advisory-body`
  - GIVEN the step WHEN inspected THEN it makes NO HTTP call to this instance and writes NOTHING into decidiq's register — gate-27 forbids the registry as an RPC bus and ADR-022/066 forbid reaching into another app's register
  - GIVEN a second run THEN nothing is created
  - GIVEN no session THEN the writes still succeed (runAsSystem), and a failure to obtain one FAILS rather than warning
  - GIVEN the test fake THEN it implements `runAsSystem()`, so removing the wrapper breaks the suite
- [x] Implement — `lib/Repair/MigrateCommitteesToDecidiq.php`, dispatching through `lib/Service/Bezwaar/CommitteeDelegationService.php`
- [x] Test

### Task 2: Roster fan-out
- **spec_ref**: `...#requirement-req-mcd-002-the-roster-fans-out-to-memberships`
- **files**: `lib/Repair/MigrateCommitteesToDecidiq.php`
- **acceptance_criteria**:
  - GIVEN a committee with N members WHEN migrated THEN N Memberships exist on the new body with correct roles
  - GIVEN a re-run THEN no duplicates
- [x] Implement — the roster is built by `CommitteeDelegationService::rosterOf()`; the fan-out to Person + Membership happens in decidiq's `GovernanceBodyCommandService`
- [x] Test — including the chair-repeated-in-members case, which would otherwise silently demote the chair

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


### Task 5: A flow that runs one referral end to end

- **spec_ref**: `openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md#requirement-req-mcd-001-committees-migrate-to-governance-bodies`
- **files**: `lib/Flow/DossiqEnsureCommitteeNode.php`, `lib/Settings/register.d/72-committees-to-decidiq.json`
- **acceptance_criteria**:
  - GIVEN a `bacAdviceRequest` is created THEN the seeded `Bezwaar advies` flow runs, and its FIRST step makes sure the committee is held as a governance body
  - GIVEN a committee already carrying `governanceBodyId` THEN the node short-circuits and dispatches nothing
  - GIVEN the decision app is absent THEN the step FAILS and the run stops, rather than referring the objection to a committee in no shared register
  - GIVEN an advice request naming no committee THEN it passes through untouched, so one flow serves both routes
- [x] Implement
- [x] Test
