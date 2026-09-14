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
