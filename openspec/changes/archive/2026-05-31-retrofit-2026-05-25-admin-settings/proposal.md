# Retrofit — admin-settings

Describes observed behavior of `SettingsController` + `SettingsService` runtime endpoints as 2 new REQs on the `admin-settings` capability. Code already exists — this change retroactively specifies it.

The existing 15 REQs (REQ-ADMIN-001..015) cover the admin panel UX surface (case types / status types / decision types / publishing / validation). These REQs document the underlying runtime contracts: the JSON settings endpoints (`index`/`create`/`load`) and the OpenRegister-aware resolver helpers that every other Procest service uses to reach the configured register/schema IDs.

## Affected code units
- lib/Controller/SettingsController.php (3 action methods) — `index()`, `create()`, `load()`
- lib/Service/SettingsService.php (5 public methods central to ADR-022) — `isOpenRegisterAvailable()`, `getObjectService()`, `loadConfiguration()`, `getSettings()`/`updateSettings()`, `getConfigValue()`/`setConfigValue()`

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
