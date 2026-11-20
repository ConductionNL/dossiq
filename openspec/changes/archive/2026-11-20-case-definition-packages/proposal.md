---
kind: code
depends_on: []
---

# Proposal: case-definition-packages

## Why

Decisions 182, 184 and 185 (hydra ADR-118): a Dutch procedure is configuration, a case type with its flows, schemas and seed rows, and code is generic. Today every procedure dossiq ships arrives through its own seeder class (`SeedBezwaarBeroepData`, `VthSeedDataRepairStep`, `SeedBesluitvormingTemplates`, `SeedLhsMatrix` and 20 more). Each one reads its own JSON shape, writes rows its own way, and only some record what they wrote. Adding a procedure means adding code.

Capability C19 in the ADR-118 catalogue replaces them with one installer that reads a package from disk. Ruben confirmed (decision 187, refactor Q3) that it lives in dossiq first and moves to OpenRegister once a second app ships packages, so its interfaces stay app-neutral.

## What changes

- A package is a directory `lib/Settings/packages/<id>/` with a `package.json` manifest: `id`, `title`, `version`, `requires`, `parts`, and `install` (`auto` or `on-request`).
- Parts this change can write: `case-type` (`case-type.json`: the case type and its status, role, property, document and result types), `flows` (`flows/*.json` workflow templates), `seed` (`seed/*.json`, each naming the settings key of the schema its rows go to), `settings` (`settings.json`, defaults that never overrule an administrator's value).
- `PackageInstaller` validates a package, refuses it by name when this instance lacks a capability it requires (`requires`, plus what its parts imply, plus `app:<id>` for another app), writes every row or none, and records every written row in the shipped-set ledger under the package id and version. A second install of the same version writes nothing. A newer version adds its new rows and leaves rows already here alone, so a local edit survives.
- `InstallShippedPackages` repair step installs every `auto` package on install and upgrade. `occ dossiq:package:install` lists packages and installs one, all, or the packages of another directory.
- A CI workflow installs the packages on a clean instance and reads the rows back through the API.

## Impact

- New: `Service/CaseDefinition/PackageDirectory`, `PackageCapabilities`, `ShippedPackageRules`, `PackageInstaller`, `Repair/InstallShippedPackages`, `Command/InstallPackageCommand`, `.github/workflows/packages.yml`.
- Extended: `PackageValidator::validateShipped()`, `PackageWriter::writePackage()` and `undo()`, `ShippedSets` (a package's version comes from its manifest), `ShippedConfigurationService::installedVersion()`, `ledgerAvailable()` and an optional version on `stamp()`.
- No seeder moves in this change. Each seeder becomes a package in its own pull request, which deletes the seeder classes.
