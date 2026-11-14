## ADDED Requirements

### Requirement: An edit takes the platform lock and names its holder (REQ-CM-39)

Opening the case edit form SHALL take OpenRegister's object lock and
closing it SHALL release it. While another user holds the lock, the case
header SHALL show the holder and the time, and Edit SHALL be disabled with
that message. A write refused by the lock SHALL show the holder's name and
keep the input.

#### Scenario: Two handlers, one lock
@e2e tests/e2e/case-edit-lock.spec.ts

- **GIVEN** Anna has the edit form of a case open
- **WHEN** you open the same case
- **THEN** the header SHALL read "Being edited by Anna since <time>"
- **AND** Edit SHALL be disabled

#### Scenario: Leaving releases
@e2e tests/e2e/case-edit-lock.spec.ts

- **GIVEN** Anna leaves the page without saving
- **WHEN** you reload the case
- **THEN** Edit SHALL be enabled
