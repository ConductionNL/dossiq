# Tasks: delegated-authority-guard

- [x] 1. `Transitions/DelegatedAuthorityGuard` asking decidiq through FleetAppId, unit tests on a neutral fixture case type
- [x] 2. GuardRegistry: `delegatedAuthorityGuard`, legacy `mandaatGuard` with `actField: mandaatCategorie`; `bvw-mandaatbesluit` declares the new type
- [x] 3. Delete `MandaatGuard`, `MandaatValidationService`, `MandaatController`, the `mandaat-check` route, the two config keys and their tests
- [ ] 4. Live: a Mandaatbesluit case with and without a decidiq toedeling for its `mandaatCategorie` (needs the live instance)
