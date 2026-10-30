---
kind: code
depends_on: [case-definition-packages, besluitvorming-package]
---

# Proposal: subsidie-package

## Why

Hydra ADR-118 (decisions 182, 184, 185): a procedure is configuration. The generic subsidie case type sat as a parked entry (`_caseTypes_disabled`) in the old bezwaar seed, which #3669 deleted, so no instance could install it. It becomes an on-request package.

## What changes

- The subsidie case type ships as the package `lib/Settings/packages/subsidie/`, `install: on-request`, converted once by `scripts/packages/seed-to-package.py` from `lib/Settings/bezwaar_seed_data.json` at the installer branch (`--git-ref`, `--only subsidie`, `--published`). It holds the case type (category "Subsidies"), 5 statuses, 3 roles and one published flow, all with fixed ids.
- The package declares the property definition `beschiktBedrag` (number, at least 0), because the flow's "Subsidie beschikken" transition has a `requiredField` guard that names it.
- A Newman read-back collection and a Playwright journey prove the install.

## Impact

- An administrator installs the case type with `POST /api/packages/subsidie/install` or `occ dossiq:package:install subsidie`. New instances do not get it on upgrade.
- `Repair/MigrateSubsidieRegelingToCaseType` stays until its one-release window ends: it moves instance data (subsidieRegeling rows into case types), not shipped configuration.
- The per-scheme demo case types in `register.d/50-subsidie.json` are untouched.
