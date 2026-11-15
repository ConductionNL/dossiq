# Design: procedures-are-configuration-dossiq

The rule, the package format, the testing split and the capability catalogue are in hydra `openspec/changes/procedures-are-configuration/design.md`. This file says what is particular to dossiq.

## What dossiq already has

- **The package vehicle.** `Service/CaseDefinition/PackageValidator`, `PackageWriter` and `WorkflowDeployer` validate and write a case definition package with rollback. `Service/Starter/ShippedConfigurationService` and `ShippedSets` record what was seeded and whether it changed. Lane R1 extends these to read packages from `lib/Settings/packages/`.
- **Case type templates.** `lib/Settings/templates/*.json` and `lib/Settings/vth-templates/*.json` already hold case type, status, property, document, decision and role types as data. They become the `case-type.json` part of a package.
- **Seed data.** `bezwaar_seed_data.json`, `vth_seed_data.json`, `lhs_matrix_seed.json`, `termijnbewaking_seed_data.json`, `woo-refusal-grounds.snapshot.json` and `seed/vth-workflow-templates/*` are package seed data already. Only their seeders are code.
- **Generic cores** that capabilities extend: `Service/Term`, `Service/Pause`, `Service/Intake`, `Service/Obligations`, `Service/Relation`, `Service/Milestone`, `Service/Doorlooptijd`.

## Packages dossiq will ship

| Package | Built from | Capabilities it needs |
|---|---|---|
| `woo-verzoek` | `templates/woo-verzoek.json`, `register.d/81` to `84`, refusal grounds | C01, C02, C03, C04, C05, C06, C07, C08, C09, C10, C21, C23 |
| `bezwaar` and `beroep` | `bezwaar_seed_data.json`, `SeedBezwaarWorkflowDefinition` | C01, C02, C03, C11, C12, C13, C14, C24 |
| `beschikking` (decision on an application) | `register.d/30-beschikking.json` | C03, C04, C17 |
| `omgevingsvergunning`, `handhavingszaak`, `toezichtzaak`, `sloopmelding` | `vth-templates/*`, `seed/vth-workflow-templates/*` | C01, C10, C15, C16, C18, C22 |
| `besluitvorming` (college, raad, mandaat) | `templates/bvw-*.json` | C03, C09, C17 |
| `subsidie` | `register.d/50-subsidie.json` | C03, C04, C15, C16, C20 |
| `dso-intake` | `register.d/dso-omgevingsloket.json` | C01, C10 |
| `avg-verzoek` | `templates/avg-verzoek.json` | C01, C02 |

## Screens

dossiq has boards per procedure: DqBezwaar, DqSubsidie, DqHandhaving, DqVthInstellingen, DqInspectie, DqTermijnen. Under ADR-118 a procedure page becomes a case type view: the case page renders the sections its case type declares. Whether those boards are redrawn as case type views is question Q1 for Ruben.
