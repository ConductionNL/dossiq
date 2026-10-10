# Tasks: portal-permits-as-held-products

Kind: code. Size M. Row: portaliq `dem-rm-my-products` (decision 105). Board: ThemaOverzicht.

## 1. Schema and case type

- [x] 1.1 (`register.d/85-permits.json`; flat `kenteken` and `adres` instead of `details.*`, because portaliq's ThemeTagKeys drops a dotted meta field: design D1 amended; `tests/Unit/Settings/PermitSchemaTest.php`) Add the `permit` schema of design D1 as a `lib/Settings/register.d/` fragment (next free prefix), and `issuesPermit` (`kind`, `theme`, `titleTemplate`, `detailsFromCase`, `changeCaseType`) on `caseType`. Verify: re-import shows no `PARTIAL IMPORT` line; `tests/Unit/Settings/PermitSchemaTest.php` asserts every property has a title.
- [x] 1.2 (`85-permits.json` seeds both with statuses, result types and property definitions; `PermitSchemaTest::testTheParkingCaseTypesAreSeeded`; the clean-instance import is the live pass) Seed the case types "Parkeervergunning bewoners" and "Kenteken wijzigen parkeervergunning" with `issuesPermit`. Verify: a seed import on a clean instance lists both.

## 2. Issuing

- [ ] 2.1 (waits on Q-dossiq-L2-4: nothing in dossiq marks a decision or result as granted) Add `lib/Service/Permit/PermitIssuer.php` and `lib/Listener/PermitFromDecisionListener.php` per design D2 (create once per decision, revoke, plate change). Verify: `tests/Unit/Service/Permit/PermitIssuerTest.php` covers grant, a repeated event, revoke and a plate change.

## 3. Contribution

- [x] 3.1 (`CitizenManifest::permitCollection()` and `permitPlateAction()`; `tests/Unit/Portal/CitizenManifestPermitTest.php`; a revoked permit is out by `defaultFilters`, the only filter key portaliq has) Add the `mijnVergunningen` collection and the `changePermitPlate` action to `lib/Portal/CitizenManifest.php` with the keys in the design's screen table; add the field constants to `lib/Portal/PortalContributionProvider.php`. Verify: `tests/Unit/Portal/CitizenManifestPermitTest.php` asserts the keys, the scope field, that every projected field exists on `permit`, and that a revoked permit is filtered.
- [x] 3.2 (an endpoint row action to `POST /api/portal/vergunning/kenteken`, not a portaliq `type: update`, which would write the permit itself: design D3 amended, flagged in Q-dossiq-L2-4; `PermitPlateChange` + `PortalPermitController`, `tests/Unit/Service/Permit/PermitPlateChangeTest.php`, `tests/Unit/Controller/PortalPermitControllerTest.php`) Route the action's write to a change case per design D3. Verify: `tests/Unit/Portal/PermitPlateChangeTest.php` asserts a case is created and the permit is unchanged.

## 4. Docs

- [x] 4.1 (`docs/Features/portal-permits.md`, the repo's docs folder; the docs build runs in CI) Describe permits on the portal in `docs/features/portal.md`. Verify: `npm run build` in `docs/` succeeds.
