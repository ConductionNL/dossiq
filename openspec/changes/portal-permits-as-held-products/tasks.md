# Tasks: portal-permits-as-held-products

Kind: code. Size M. Row: portaliq `dem-rm-my-products` (decision 105). Board: ThemaOverzicht.

## 1. Schema and case type

- [ ] 1.1 Add the `permit` schema of design D1 as a `lib/Settings/register.d/` fragment (next free prefix), and `issuesPermit` (`kind`, `theme`, `titleTemplate`, `detailsFromCase`, `changeCaseType`) on `caseType`. Verify: re-import shows no `PARTIAL IMPORT` line; `tests/Unit/Settings/PermitSchemaTest.php` asserts every property has a title.
- [ ] 1.2 Seed the case types "Parkeervergunning bewoners" and "Kenteken wijzigen parkeervergunning" with `issuesPermit`. Verify: a seed import on a clean instance lists both.

## 2. Issuing

- [ ] 2.1 Add `lib/Service/Permit/PermitIssuer.php` and `lib/Listener/PermitFromDecisionListener.php` per design D2 (create once per decision, revoke, plate change). Verify: `tests/Unit/Service/Permit/PermitIssuerTest.php` covers grant, a repeated event, revoke and a plate change.

## 3. Contribution

- [ ] 3.1 Add the `mijnVergunningen` collection and the `changePermitPlate` action to `lib/Portal/CitizenManifest.php` with the keys in the design's screen table; add the field constants to `lib/Portal/PortalContributionProvider.php`. Verify: `tests/Unit/Portal/CitizenManifestPermitTest.php` asserts the keys, the scope field, that every projected field exists on `permit`, and that a revoked permit is filtered.
- [ ] 3.2 Route the action's write to a change case per design D3. Verify: `tests/Unit/Portal/PermitPlateChangeTest.php` asserts a case is created and the permit is unchanged.

## 4. Docs

- [ ] 4.1 Describe permits on the portal in `docs/features/portal.md`. Verify: `npm run build` in `docs/` succeeds.
