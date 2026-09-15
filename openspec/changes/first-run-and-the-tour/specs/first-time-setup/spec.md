## ADDED Requirements

### Requirement: The first run names the minimum an instance needs (REQ-SETUP-010)

dossiq SHALL declare the minimum configuration an instance needs before it
can take a case: an organisation, a selected mail account, at least one
published case type, at least one role with a holder, and a working
calendar. Each item SHALL be read live at request time and SHALL NOT be a
stored completion flag. An item whose read fails SHALL report not done and
SHALL name the failure. Only `register-check` SHALL gate the app; no
readiness item SHALL block it.

#### Scenario: an administrator sees what is still missing
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** an instance with a register but no published case type
- **WHEN** an administrator opens the first run screen
- **THEN** the case type item SHALL read not done
- **AND** the organisation item SHALL read done

#### Scenario: a readiness item re-reads itself
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** a readiness item reading not done
- **WHEN** the administrator satisfies it and returns
- **THEN** it SHALL read done without the page being reinstalled

#### Scenario: an unconfigured instance is still usable

- **GIVEN** an instance where four of the five items read not done
- **WHEN** an administrator opens the app
- **THEN** the navigation SHALL be reachable
- **AND** only `register-check` SHALL gate it

#### Scenario: an item whose read throws is not done

- **GIVEN** a readiness item whose read raises
- **WHEN** the status is read
- **THEN** the item SHALL report not done
- **AND** it SHALL name the failure

### Requirement: Every readiness item names the screen that satisfies it (REQ-SETUP-011)

Each readiness item SHALL name the screen an administrator goes to in order
to satisfy it. The declared item list and the reported item list SHALL
agree in both directions: no item SHALL be rendered that the status does
not report, and no item SHALL be reported that no screen can satisfy.

#### Scenario: an item leads somewhere
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** the mail account item reading not done
- **WHEN** an administrator follows it
- **THEN** they SHALL land on the mail settings screen

#### Scenario: the two lists agree

- **GIVEN** the declared readiness items and the reported ones
- **WHEN** they are compared
- **THEN** every declared item SHALL be reported
- **AND** every reported item SHALL be declared

### Requirement: The tour is per surface and per person (REQ-SETUP-012)

Tour completion SHALL be recorded per person and per surface, not once for
the whole app. A person who has finished every existing surface SHALL be
offered the step for a surface added later. A tour step naming a surface
that no longer exists SHALL be reported as broken beside the readiness
items and SHALL NOT be silently skipped.

#### Scenario: a handler who joined later is still taught
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** an instance whose administrator finished the tour
- **WHEN** a new handler opens the app for the first time
- **THEN** they SHALL be offered the tour

#### Scenario: a new surface is offered to someone who finished the rest
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** a person who completed every tour step
- **WHEN** a surface with its own step is added
- **THEN** that step SHALL be offered to them
- **AND** the finished steps SHALL NOT be offered again

#### Scenario: a step that lost its surface is reported

- **GIVEN** a tour step naming a page that no longer exists
- **WHEN** the first run status is read
- **THEN** the step SHALL be reported as broken
- **AND** it SHALL name the missing surface
