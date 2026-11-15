# subsidieverlening-keten delta: subsidie-package

## ADDED Requirements

### Requirement: REQ-SUB-011 The subsidie case type ships as an on-request package

The app SHALL ship the generic subsidie case type as the case definition package `subsidie`, `install: on-request`, holding the case type with category "Subsidies", its statuses, roles and published flow, and the property definition `beschiktBedrag` that the flow's beschikking transition requires. Installing it twice SHALL write nothing the second time.

#### Scenario: An administrator installs the subsidie package
@e2e tests/e2e/packages/subsidie.spec.ts

- **GIVEN** an administrator on an instance without the subsidie package
- **WHEN** they post to `/api/packages/subsidie/install`
- **THEN** the response SHALL be 200 with status `installed` or `current`
- **AND** exactly one case type with identifier `subsidie` SHALL exist, showing its flow "Subsidie Standaard Workflow"
- **AND** a second post SHALL answer status `current`

#### Scenario: A beschikking needs the beschikt bedrag
@e2e exclude no user interface of its own; the guard sweep in tests/Unit/Settings proves every requiredField guard names a declared property

- **GIVEN** a subsidie case in status "In behandeling" without `beschiktBedrag`
- **WHEN** a handler moves it to "Beschikt"
- **THEN** the transition SHALL be refused naming `beschiktBedrag`
