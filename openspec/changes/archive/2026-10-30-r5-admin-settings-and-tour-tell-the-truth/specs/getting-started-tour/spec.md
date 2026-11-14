# getting-started-tour Delta: r5-admin-settings-and-tour-tell-the-truth

## ADDED Requirements

### Requirement: The tour says only what the page shows

A step of the `dossiq:getting-started` tour SHALL NOT state a count the
instance does not hold, SHALL name a page the way the page names itself, and
SHALL describe the list it points at as that list describes itself.

#### Scenario: The case types step
<!-- @e2e exclude Copy contract; proven by tests/vitest/tourTellsTheTruth.spec.js. -->
- **WHEN** the tour reaches the case types step
- **THEN** its text names no number of case types

#### Scenario: The last step
<!-- @e2e exclude Copy contract; proven by tests/vitest/tourTellsTheTruth.spec.js. -->
- **WHEN** the tour reaches its last step
- **THEN** title and text both say dashboard, the label of the Dashboard menu item

#### Scenario: The cases step
<!-- @e2e exclude Copy contract; proven by tests/vitest/tourTellsTheTruth.spec.js. -->
- **GIVEN** the cases list says it shows the open cases in your teams
- **WHEN** the tour reaches the cases step
- **THEN** its text says open cases in your teams, and not every case, open and closed
