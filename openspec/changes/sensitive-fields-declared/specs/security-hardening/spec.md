## ADDED Requirements

### Requirement: Sensitive fields are declared behind an extra group (REQ-SEC-SF-1)

Every BSN field and every special-category field on dossiq's schemas SHALL
carry a `row-field-level-security` field rule readable by group
`dossiq-sensitive` only, with the reveal audited by OpenRegister.
`CitizenLookupGuard` SHALL NOT duplicate the field check.

#### Scenario: A handler outside the group does not see the BSN
@e2e tests/e2e/sensitive-fields.spec.ts

- **GIVEN** a handler not in `dossiq-sensitive`
- **WHEN** they open a case with a requester BSN
- **THEN** the BSN field SHALL be hidden

#### Scenario: A reveal is audited
@e2e tests/e2e/sensitive-fields.spec.ts

- **GIVEN** a member of `dossiq-sensitive`
- **WHEN** they reveal the BSN
- **THEN** OpenRegister's audit SHALL hold a field-access row for it

#### Scenario: The guard no longer decides
@e2e exclude structural; a unit test asserts CitizenLookupGuard has no field-level branch

- **GIVEN** `CitizenLookupGuard`
- **WHEN** its methods are listed
- **THEN** none SHALL check a field's group
