---
kind: code
depends_on: [case-definition-packages]
---

# Proposal: dso-intake-package

## Why

Hydra ADR-118 (decisions 182, 184, 185): a procedure is configuration. DSO intake was turned on by code of its own: the repair step `DefaultDsoIntakeSchema` looked up integriq's `dso_verzoek` schema on every install and upgrade and wrote its id to `dso_vergunningaanvraag_schema`. Decision 198 extended the installer so a package setting can name another app's schema by register and slug, and so a package that writes no rows is recorded at its version. That makes the repair step a package.

## What changes

- The settings-only package `lib/Settings/packages/dso-intake/`, `install: auto`. Its one setting names integriq's `dso_verzoek` schema in register `integriq` (or the old `openconnector`). The installer resolves it at install time (case-definition-packages REQ-CT-53) and records the package at its version (REQ-CT-54).
- An administrator's value, an empty one included, is kept, as before.
- Without integriq, or without its `dso_verzoek` schema, the package is refused and the upgrade log names integriq. Nothing is recorded, so the next upgrade tries again, as the repair step did.
- Deleted: `Repair/DefaultDsoIntakeSchema`, its two `info.xml` entries and `DefaultDsoIntakeSchemaTest`. `DsoIntakeCheck` keeps its own name for the key.
- Newman collection `tests/integration/packages/dso-intake.postman_collection.json` and Playwright journey `tests/e2e/packages/dso-intake.spec.ts`.

## Impact

- On an instance where the repair step already set the key, the package applies nothing and records version 1.0.0.
- An instance without integriq now gets a warning line for dso-intake on each upgrade, where the repair step said "integriq is not installed" at info level. The setup check already warned that DSO intake was off.
