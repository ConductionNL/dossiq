## ADDED Requirements

### Requirement: A case type is versioned, and a running case stays on its version (REQ-ZV-02)

You change a published case type by making a new version of it, not by editing
it. A published case type SHALL offer New version, which SHALL create a draft
carrying the same title and the same identifier, one version number higher,
linked back through `previousVersion`, with its own copy of every status,
result, role, property, document type and decision type. The version being
succeeded SHALL NOT be written to. Publishing the new version SHALL write
`supersededBy` onto the version it replaces. A superseded version SHALL keep
its cases running and SHALL NOT be offered for new cases.

A case SHALL stay on the version it started under, and SHALL NOT be migrated to
a newer one. No property is added to the case: `caseType` already names one
specific version.

**Feature tier**: V1

#### Scenario: A new version leaves the running cases alone
@e2e exclude Version-chain writes are backend logic, covered by tests/Unit/Service/CaseTypeCopyServiceTest.php and CaseTypePublishServiceTest.php.

- **GIVEN** a published case type Vergunning at version 2 with five running cases
- **WHEN** the administrator chooses New version
- **THEN** a draft version 3 SHALL exist, linked back to version 2
- **AND** version 2 SHALL be unchanged, including its statuses and its initial status
- **AND** the five cases SHALL still resolve their type, statuses and deadline through version 2

#### Scenario: Publishing a version closes the one it replaces
@e2e exclude Backend write, covered by tests/Unit/Service/CaseTypePublishServiceTest.php.

- **GIVEN** draft version 3 linked back to published version 2
- **WHEN** the administrator publishes version 3 with a change note
- **THEN** version 2 SHALL carry `supersededBy` naming version 3
- **AND** version 2 SHALL still not be a draft, because its cases still run on it

#### Scenario: Only the current version is offered for a new case
@e2e exclude Pure rule, covered by tests/vitest/caseTypeVersion.spec.js.

- **GIVEN** version 2 superseded by version 3, both published and both valid today
- **WHEN** somebody starts a new case
- **THEN** only version 3 SHALL be offered
- **AND** asking for version 2 by id SHALL be refused with a sentence naming the replacement, not one about drafts or expiry

#### Scenario: A duplicate is not a version
@e2e exclude Backend write, covered by tests/Unit/Service/CaseTypeCopyServiceTest.php.

- **GIVEN** a published case type
- **WHEN** the administrator chooses Duplicate
- **THEN** the copy SHALL carry a new identifier, a "Copy of" title and version 1
- **AND** it SHALL carry no `previousVersion`, because it is a second case type rather than the same one later on

### Requirement: A copied case type starts its own cases in its own status (REQ-ZV-03)

Copying or versioning a case type SHALL repoint the new type's `initialStatus`
at its own copy of that status. A new type whose initial status names a status
owned by another type files cases into a lifecycle it cannot move them out of.

**Feature tier**: V1

#### Scenario: The initial status follows the copy
@e2e exclude Backend write, covered by tests/Unit/Service/CaseTypeCopyServiceTest.php.

- **GIVEN** a case type whose initial status is its own status Ontvangen
- **WHEN** it is duplicated or versioned
- **THEN** the new type's initial status SHALL be the new type's own copy of Ontvangen
- **AND** publish validation SHALL NOT ask for a status the administrator already picked
