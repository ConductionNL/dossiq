# migrate-committees-to-decidiq Specification

**Status**: planned (BLOCKED on decidiq#874)
**Scope**: dossiq

## Purpose

Read objection advisory committees from decidiq's `GovernanceBody` instead of dossiq's own `bezwaaradviescommissie`, and migrate the existing ones across. The target only became able to hold a committee in decidiq#874.

## ADDED Requirements

### Requirement: REQ-MCD-001 Committees migrate to governance bodies

The system SHALL provide a repair step that creates a decidiq `GovernanceBody` for each local `bezwaaradviescommissie`, mapping `name`, `domain`, `jurisdiction`, `quorum`, `active`, `termStartsOn`/`termEndsOn`, and setting `bodyType: advisory-body` with `statutoryBasis` naming Awb 7:13.

The step SHALL run under a system identity. A repair step executes during `occ upgrade`, where there is no session; without one, OpenRegister resolves the actor as `Anonymous` and refuses every create, and the resulting `$output->warning()` does NOT fail the upgrade — so the migration silently does nothing while the upgrade reports success.

The step SHALL record the resulting body id on the dossiq side BEFORE creating any memberships, and SHALL skip a committee that already carries one.

#### Scenario: A committee becomes a governance body

- GIVEN a `bezwaaradviescommissie` with a quorum of 3 and `active: true`
- WHEN the migration runs
- THEN a decidiq `GovernanceBody` exists with `bodyType: advisory-body`, `quorum: 3`, `active: true` and a `statutoryBasis` naming Awb 7:13
- AND the local committee records the new body's id

#### Scenario: Re-running mints nothing new

- GIVEN a committee already carrying a migrated body id
- WHEN the migration runs again
- THEN no second body and no duplicate memberships are created

#### Scenario: The step has an identity

- GIVEN the migration running with no user session
- WHEN it writes
- THEN the writes succeed
- AND a run that could not establish a system identity FAILS rather than reporting a warning and continuing

### Requirement: REQ-MCD-002 The roster fans out to memberships

The system SHALL create one `Membership` per entry in the committee's `members[]`, linked to the new body, with `role` derived from the chair/secretary/member position and `external` set for members who sit from outside the administrative organ.

`members[]` is a list of uids on ONE object; decidiq models the roster as separate `Person` + `Membership` rows. The fan-out is therefore not a field copy, and it is the part that must not run twice.

#### Scenario: The chair is recorded as chair

- GIVEN a committee whose `chair` names one of its members
- WHEN the roster is migrated
- THEN that member's Membership carries `role: chair`
- AND an Awb 7:13(2) external chair carries `external: true`

### Requirement: REQ-MCD-003 Reads resolve from decidiq, falling back locally

The system SHALL resolve committees from decidiq, falling back to the local schema when decidiq is absent or the committee has not been migrated.

The fallback SHALL NOT be treated as a migration-window convenience. decidiq is an OPTIONAL runtime dependency, so an install without it must keep working; the branch is permanent until decidiq becomes required.

The `active` flag SHALL be read from whichever source served the committee. `AdvisoryCommitteeService` refuses new bezwaren on an archived committee, so a read that loses `active` starts routing objections to disbanded committees — and, defaulting to available, does so without erroring.

#### Scenario: decidiq absent

- GIVEN an instance without decidiq
- WHEN a committee is read
- THEN it resolves from the local schema
- AND no error is raised

#### Scenario: The archive gate still holds after migration

- GIVEN a migrated committee with `active: false`
- WHEN a new bezwaar is assigned to it
- THEN it is refused, exactly as before the migration
