# vth-case-type-seed delta: vth-package

## ADDED Requirements

### Requirement: The VTH case types ship as a case definition package

The app SHALL ship the six VTH case types as the case definition package `vth`, `install: auto`, with their statuses, roles, fields and document types and the three inspection checklists of toezichtzaak bouw. Each case type SHALL carry an `identifier` equal to its former seed slug. On an instance where the former VTH seeder ran, the installer SHALL adopt the existing case types and checklists and SHALL NOT write a second one.

#### Scenario: A clean install has the six VTH case types
@e2e tests/e2e/packages/vth.spec.ts

- **GIVEN** a clean instance after install
- **WHEN** an administrator lists the case types
- **THEN** exactly one case type SHALL exist for each of `omgevingsvergunning-bouwactiviteit`, `sloopmelding`, `toezichtzaak-bouw`, `toezichtzaak-milieu`, `handhavingszaak` and `invorderingszaak`

#### Scenario: An upgraded instance keeps one case type per title
@e2e tests/e2e/packages/vth.spec.ts

- **GIVEN** an instance where the former VTH seeder wrote the six case types without an identifier
- **WHEN** the upgrade installs the `vth` package
- **THEN** each title SHALL still have exactly one case type
- **AND** that case type SHALL keep its id

#### Scenario: Toezichtzaak bouw has its three checklists
@e2e tests/e2e/packages/vth.spec.ts

- **GIVEN** an instance with the `vth` package installed
- **WHEN** an administrator reads the inspection checklists of toezichtzaak bouw
- **THEN** "Bouwtoezicht fase 1 - Fundering", "Bouwtoezicht fase 2 - Ruwbouw" and "Bouwtoezicht fase 3 - Oplevering" SHALL each exist once
