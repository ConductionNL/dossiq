# Tasks: site-woo-request-in-steps

Tier: V1. Kind: code. Programme: portal-design (2026-10-02). Mockup: `DossiqWoo.dc.html`.
Builds on `woo-request-from-a-portal-dossier`. Depends on portaliq `site-multi-step-forms`
(portaliq#1110) for the step form, the draft store and the confirmation page;
without it portaliq shows the action as one form, as today.

## 0. Decision for Ruben

- [ ] 0.1 Required fields (design D1). Fact: portaliq does not honour `required` on an action
      without a schema (REQ-SMF-023), so every Woo field reads "(niet verplicht)". Accept that,
      or make `omschrijving`, `periodeVan`, `documentSoorten`, `verzoekerNaam` and
      `verzoekerEmail` required on dossiq's portal route AND lift portaliq's rule for such a
      route. Until decided, no field is declared required.

## 1. Intake

- [ ] 1.1 `WooRequestForm` accepts and validates the five new fields (design D1);
      `case.wooRequest` declares them in `register.d/81-woo-verzoek.json`; the requester fields
      fill the intake properties.
  - unit: `WooRequestIntakeTest` for each field, an unknown document kind, a bad address,
    pipelinq without the new fields
- [ ] 1.2 `start()` answers `identifier` and `deadline`, and leaves `deadline` out when empty
      (design D4).
  - unit: both cases
- [ ] 1.3 `PortalWooRequestController::FIELDS` forwards the new fields.
  - unit: `PortalWooRequestControllerTest` asserts they reach `start()`

## 2. Actions

- [ ] 2.1 `steps` (with `description`, and `review: true` on the last), `fieldConfigs`
      (no `required`), `draft` and `confirmation` on `startWooVerzoek`
      (design D1, D3, D4).
- [ ] 2.2 `startWooVerzoekAlgemeen` without `attachTo` (design D2), with the `summary` the home
      tile reads (`site-resident-portal-design` REQ-SRPD-006).
  - unit: `PortalContributionProviderTest` asserts both actions, that every field sits in one
    step, and that only the dossier variant has `attachTo`

## 3. Validation

- [ ] 3.1 `openspec validate site-woo-request-in-steps --strict`, `npm run lint`,
      `composer check:strict` once before push.
- [ ] 3.2 Live with `site-multi-step-forms`: a resident starts a request from the home page,
      saves on step 2, comes back, sends, and reads the case number on the confirmation.
