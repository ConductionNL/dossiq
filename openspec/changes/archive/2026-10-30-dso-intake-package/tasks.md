# Tasks: dso-intake-package

- [x] 1.1 Package `lib/Settings/packages/dso-intake/` (settings only, `install: auto`) naming integriq's `dso_verzoek` by register and slug.
  - validated and installed by `.github/workflows/packages.yml` (`occ dossiq:package:install --all`).
- [x] 1.2 Newman collection `tests/integration/packages/dso-intake.postman_collection.json`, run by the packages workflow on a clean instance with integriq.
- [x] 1.3 Playwright journey `tests/e2e/packages/dso-intake.spec.ts`.
- [x] 1.4 Delete `Repair/DefaultDsoIntakeSchema`, its `info.xml` entries and `DefaultDsoIntakeSchemaTest`.
