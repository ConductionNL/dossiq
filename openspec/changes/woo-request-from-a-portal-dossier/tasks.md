# Tasks: woo-request-from-a-portal-dossier

Tier: V1. Kind: code. Contract: hydra `woo-citizen-journey` C5.

## 1. The case type

- [ ] 1.1 Seed the Woo request case type (`register.d/81-woo-verzoek.json`,
  design D1), declare `case.wooRequest` (D3), remove `templates/woo_verzoek.json`,
  bump the case schema and register versions.
  - unit: `WooVerzoekSeedTest` asserts the type, eight statuses, four result
    types, the portal windows and the deadline
  - `composer check:strict` exit 0

## 2. The creation path

- [ ] 2.1 `OCA\Dossiq\Woo\WooRequestIntake::start()` (D2).
  - unit: `WooRequestIntakeTest` covers the case write, one case object per
    item, `sourceOf` appended once, someone else's dossier (not_found), a
    missing dossier (not_found), no dossier, a bad period, a bad origin

## 3. The portal action

- [ ] 3.1 `PortalAssertionVerifier` and `PortalWooRequestController::start`
  with its route (D4).
  - unit: `PortalAssertionVerifierTest`, `PortalWooRequestControllerTest`
    (201, 401, 400, 404)
- [ ] 3.2 `startWooVerzoek` in the citizen contribution.
  - unit: `PortalContributionProviderTest` asserts the action for `citizen`
    and `client`, its endpoint, method and fields

## 4. Live

- [ ] 4.1 Coordinator, on :8080 after opencatalogi's `collection` lands: a
  resident starts a Woo request from a dossier with two items; the case shows
  in Mijn zaken with its deadline and two case objects; the dossier's
  `sourceOf` holds the case.
