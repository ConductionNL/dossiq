# case-types delta: bezwaar-beroep-package

## ADDED Requirements

### Requirement: Bezwaar and beroep install on request as one package (REQ-CT-50)

The app SHALL ship the bezwaar and beroep case types, their status types, role types, guarded fields and flows as the case definition package `bezwaar-beroep`, marked `install: on-request`. The setup action `seed` SHALL install that package through the package installer. When the package is installed or already current the action SHALL answer success and record `setup_seed_done`; when the installer refuses it or a write fails the action SHALL answer 422 with the installer's message and SHALL NOT record the step. Every transition in the package's flows SHALL run between two statuses of its case type, and every field a transition guards on SHALL be declared for that case type.

#### Scenario: An administrator installs bezwaar and beroep

- **GIVEN** an administrator on an instance without the bezwaar and beroep case types
- **WHEN** they run the setup action `seed`
- **THEN** the action SHALL answer success
- **AND** the Bezwaar and Beroep case types SHALL open on the case type page, each with its published flow
- **AND** running the action again SHALL answer success and write nothing
