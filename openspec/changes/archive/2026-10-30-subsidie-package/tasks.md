# Tasks: subsidie-package

- [x] 1.1 Convert the parked subsidie entry into `lib/Settings/packages/subsidie/`, `install: on-request`, with category "Subsidies" and the property definition `beschiktBedrag`.
  - validated by `tests/Unit/Settings` (package category, guard and payload sweeps)
- [x] 1.2 Newman collection `tests/integration/packages/subsidie.postman_collection.json`, written by `scripts/packages/package-to-newman.py` and run by the packages workflow on a clean instance.
- [x] 1.3 Playwright journey `tests/e2e/packages/subsidie.spec.ts`.
