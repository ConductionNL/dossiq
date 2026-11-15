## ADDED Requirements

### Requirement: The case type page shows its version chain (REQ-ZV-04)

You see every version of a case type on its page. `#CaseTypeDetail` SHALL
carry a Version chain panel listing every `caseType` row with the same
`identifier`, newest version first, with version, draft or published, valid
from and valid until.

#### Scenario: Two versions are listed
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a case type with a published version 1 and a draft version 2
- **WHEN** you open the page of version 1
- **THEN** the Version chain panel SHALL list version 2 above version 1
- **AND** version 2 SHALL read as draft

### Requirement: New version and Deprecate are actions on the page (REQ-ZV-05)

You start the next version from the page. `#CaseTypeDetail` SHALL offer New
version, which calls `POST /api/case-definitions/{id}/new-version` and opens
the created draft, and Deprecate, which sets `validUntil` to today and is
offered only on a published version that has a successor.

#### Scenario: New version opens the draft
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a published case type
- **WHEN** you press New version
- **THEN** the page of the new draft SHALL open
- **AND** its `previousVersion` SHALL reference the version you came from

#### Scenario: Deprecate is refused on the only version
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a published case type without a successor
- **WHEN** you open the header actions
- **THEN** Deprecate SHALL NOT be offered

### Requirement: The index shows the current version (REQ-ZV-06)

`#CaseTypes` SHALL list one row per identifier, the version without a
successor, and SHALL offer a chip All versions that lists every version.

#### Scenario: Superseded versions are hidden by default
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a case type with versions 1 and 2, version 2 current
- **WHEN** you open the Case types index
- **THEN** only version 2 SHALL be listed
- **AND** pressing All versions SHALL list both

### Requirement: A new version carries the workflow of the version it succeeds (REQ-ZV-07)

A new version of a case type SHALL carry the workflow templates of the version
it succeeds, and its `workflowDefinition` SHALL name its own copy of the pinned
template. When there is no copy to name, `workflowDefinition` SHALL be empty
rather than name another version's template.

#### Scenario: The next version keeps the process
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a published case type with a pinned workflow
- **WHEN** you start a new version
- **THEN** the draft SHALL carry a copy of that workflow
- **AND** its `workflowDefinition` SHALL name the copy, not the original

### Requirement: Publishing closes the version it replaces (REQ-ZV-08)

Publishing a version SHALL write `supersededBy` on the version it replaces AND
close that version's `validUntil` on the day the new version takes effect. A
`validUntil` already set SHALL NOT be moved.

#### Scenario: The replaced version stops being open ended
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a published version 1 and a draft version 2
- **WHEN** you publish version 2
- **THEN** version 1 SHALL carry `supersededBy` naming version 2
- **AND** version 1 SHALL carry a `validUntil`
- **AND** version 1 SHALL stay published, because its cases still run on it

### Requirement: A running case moves to another version only as a named act (REQ-ZV-09)

A running case SHALL stay on the version it was filed under unless somebody
moves it deliberately. `#CaseDetail` SHALL offer Move to another version, which
SHALL show, before anything is written, the status the case lands in, the
statuses and fields the target version adds and drops, and which of the dropped
fields this case has answered. The act SHALL require a reason and SHALL record
both versions, the reason and the actor on the case.

The act SHALL refuse, naming the status, when the target version carries no
status by the name the case is currently in. The act SHALL refuse a target that
is not another version of the case's own case type.

#### Scenario: The preview says where the case lands
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a case running on version 1 of a case type with a published version 2
- **WHEN** you ask what moving it to version 2 would change
- **THEN** the answer SHALL name the status it lands in
- **AND** that status SHALL be version 2's own row, not version 1's

#### Scenario: A status the target version dropped refuses the move
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a case in a status the target version does not carry
- **WHEN** you move it to that version
- **THEN** the move SHALL be refused
- **AND** the refusal SHALL name that status
- **AND** the case SHALL stay on the version it was on

#### Scenario: The same act runs over a selection
@e2e exclude a bulk job over a seeded caseload is a background run, and watching it finish means polling the job; the action's rehearsal, its refusal and its commit are asserted in tests/Unit/BulkAction/CaseBulkActionsTest.php against the same service the single-case act calls

- **GIVEN** a selection of cases on one version of a case type
- **WHEN** you run the bulk move onto another version
- **THEN** the rehearsal SHALL report each case that has nowhere to land
- **AND** it SHALL name the status for each of them
