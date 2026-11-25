# Tasks: delegated-authority-guard

- [x] 1. `Transitions/DelegatedAuthorityGuard` asking decidiq through FleetAppId, unit tests on a neutral fixture case type
- [x] 2. GuardRegistry: `delegatedAuthorityGuard`, legacy `mandaatGuard` with `actField: mandaatCategorie`; `bvw-mandaatbesluit` declares the new type
- [x] 3. Delete `MandaatGuard`, `MandaatValidationService`, `MandaatController`, the `mandaat-check` route, the two config keys and their tests
- [x] 4. Live: a Mandaatbesluit case with and without a decidiq toedeling for its `mandaatCategorie` (needs the live instance)
  - Done 25 Nov 2026 on a sandbox instance (NC 34.0.6, MariaDB, openregister main, dossiq/decidiq/portaliq development, Zuiddrecht set incl. decidiq's bevoegdheidstoedeling: Pieter, act `woo-decision`): the registered `DelegatedAuthorityGuard` under the legacy `mandaatGuard` config (`actField: mandaatCategorie`), evaluated in the running instance against decidiq's live check with Mandaatbesluit-shaped cases: Pieter signing `woo-decision` passes (authority confirmed, allocation 03f39e02); Thomas signing `woo-decision` is refused (no-allocation, "onvoldoende mandaat"); Pieter signing an act he holds no allocation for is refused (no-allocation); a case without `mandaatCategorie` is refused (no-act). Evaluated in-process, not through a full status transition of a stored case.
