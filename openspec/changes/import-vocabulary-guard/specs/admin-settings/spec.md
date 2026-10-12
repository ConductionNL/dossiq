## ADDED Requirements

### Requirement: Every shipped schema uses only what OpenRegister's import accepts (REQ-ADMIN-041)

Every schema dossiq ships SHALL use only property keys, closed-list modifier
values and extending-form roles that OpenRegister's schema import accepts, as
published by OpenRegister's property vocabulary and extending-forms reads. A
build SHALL fail when a shipped schema uses anything else, because the import
rejects the whole schema and keeps the one the instance already had.

#### Scenario: a property uses an inputControl OpenRegister refuses

- **GIVEN** a shipped property declaring `inputControl: textarea`
- **WHEN** the unit suite runs
- **THEN** the shipped-declarations test fails naming the property and the value
- @e2e exclude {build-time guard on shipped JSON, covered by a unit test and the live register import}

#### Scenario: the case form maps a role OpenRegister accepts

- **GIVEN** the case form declaration mapping `hideOnCreate`
- **WHEN** the unit suite runs against the accepted-vocabulary snapshot
- **THEN** the role is accepted and the test pins that the declaration still maps it
- @e2e exclude {build-time guard on shipped JSON, covered by a unit test and the live register import}
