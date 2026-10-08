# Tasks: dso-intake-on-by-default

## 1. Default

- [x] 1.1 Red: `tests/Unit/Repair/DefaultDsoIntakeSchemaTest.php` (fills an absent key with the id the listener matches, keeps an admin value and an admin empty, idempotent, no integriq leaves it absent, registered in both blocks)
- [x] 1.2 `lib/Repair/DefaultDsoIntakeSchema.php`, registered in `<install>` and `<post-migration>`
  - `@spec openspec/changes/dso-intake-on-by-default/specs/vth-dso-integration/spec.md`

## 2. Setup check

- [x] 2.1 Red: `tests/Unit/SetupCheck/DsoIntakeCheckTest.php`
- [x] 2.2 `lib/SetupCheck/DsoIntakeCheck.php`, registered in `Application::register()`
  - `@spec openspec/changes/dso-intake-on-by-default/specs/vth-dso-integration/spec.md`

## 3. Verify

- [x] 3.1 Live: fresh install with integriq sets the key and a verzoek becomes one case; an emptied key stays empty after a second upgrade; without integriq the setup check warns
