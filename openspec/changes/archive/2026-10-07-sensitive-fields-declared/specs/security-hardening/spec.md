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
@e2e exclude structural; a unit test asserts CitizenLookupGuard evaluates no declaration

- **GIVEN** `CitizenLookupGuard`
- **WHEN** its source is read
- **THEN** it SHALL NOT read a schema, a property or an `authorization` block
- **AND** it SHALL NOT grant a field the declaration withholds

SHARPENED 2026-09-18 by `citizen-lookup-is-guarded-and-recorded`. The first
wording was "none of its methods SHALL check a field's group", and that change
adds `redactForCaller()`, which checks exactly one group against one constant
list to take four keys OUT of a payload dossiq composed itself. That is not the
duplication this requirement forbids: the thing to forbid is a SECOND EVALUATOR
of OpenRegister's declaration, which would eventually disagree with it and be
"fixed" in whichever direction was easier. A remove-only list that can never
grant is the opposite failure mode, and the wording above says which one is
meant.
