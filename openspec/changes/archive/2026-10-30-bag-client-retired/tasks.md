# Tasks: bag-client-retired

- [x] Call sites checked with `git grep` for every deleted class in `lib/`, `appinfo/`, `src/` and `tests/`.
- [x] `Service/Registry/BagObjectReader` over integriq's reader, with integriq absent as "not available".
- [x] `LocationBagValidationListener` refuses when the check is on and unsure, marks `not-checked` when it is off.
- [x] `case-location.bagCheck` with its l10n keys.
- [x] BAG client, `BagRegistrar`, `PdokBagService` and their tests deleted.
- [x] Repair step `CarryBagCheckSetting` keeps the check on where the old client was on, registered in `appinfo/info.xml` with the version moved, tested from the registration.
- [x] The refusal names integriq when it is missing or too old.
