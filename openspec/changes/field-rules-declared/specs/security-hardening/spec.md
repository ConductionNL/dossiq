## ADDED Requirements

### Requirement: Field rules per role are declared on the case (REQ-SEC-FR-1)

The `case` schema SHALL declare `row-field-level-security` rules:
`confidentiality`, `competentAuthority` and `statutoryTerm` read-only for
handlers; `qualityScore` and `qualityStatus` hidden for handlers; all five
editable for `dossiq-coordinators` and `dossiq-quality`. No dossiq code
SHALL filter these fields.

#### Scenario: A handler cannot change the confidentiality
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** a handler editing a case
- **WHEN** the form renders
- **THEN** Confidentiality SHALL be read-only
- **AND** Quality score SHALL NOT be shown

#### Scenario: A coordinator can
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** a member of `dossiq-coordinators` editing the same case
- **WHEN** the form renders
- **THEN** Confidentiality SHALL be editable
