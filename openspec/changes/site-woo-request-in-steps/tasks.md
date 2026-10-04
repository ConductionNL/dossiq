# Tasks: site-woo-request-in-steps

Tier: V1. Kind: code. Programme: portal-design (2026-10-02). Mockup: `DossiqWoo.dc.html`.
Builds on `woo-request-from-a-portal-dossier`. Depends on portaliq `site-multi-step-forms`
(portaliq#1110) for the step form, the draft store and the confirmation page;
without it portaliq shows the action as one form, as today.

## 0. Decision for Ruben

- [ ] 0.1 Required fields (design D1). **The fact has changed since this was written.** Ruben
      decided on 3 October that an action may name its own required fields, and portaliq
      shipped it: `requiredFields` on the action is honoured whether or not the action writes a
      schema (REQ-SMF-023/024, portaliq#1139). So the half that needed portaliq is done, and
      what is left is only dossiq's own: `onderwerp` is declared required here, because it is
      the one field `WooRequestForm` already refuses a request without. Whether
      `omschrijving`, `periodeVan`, `documentSoorten`, `verzoekerNaam` and `verzoekerEmail`
      should join it means refusing them on dossiq's portal route while `start()` stays lenient
      for pipelinq's conversion. Declaring them required without that refusal would have the
      form promise a check nothing makes, so this change does neither and leaves the decision.
      `required` on a field config is still never declared: portaliq drops it in silence.

## 1. Intake

- [x] 1.1 `WooRequestForm` accepts and validates the five new fields (design D1);
      `case.wooRequest` declares them in `register.d/81-woo-verzoek.json` (case 1.36.0); the
      requester fields fill the intake properties through `WooRequesterProperties`.
  - unit: `WooRequestIntakeTest::testTheRequestKeepsTheDocumentKindsAndTheRequester`,
    `::testAnAnswerOutsideItsListIsRefusedAndWritesNothing` (an unknown kind, an unknown
    requester kind and a bad address, each writing nothing) and
    `::testAConversionWithoutTheNewAnswersCarriesNone`.
  - An answer that was not given is left out rather than written as '', so "not asked" and
    "answered empty" do not become two states a reader has to tell apart, and the schema's
    enums never see an empty value.
- [x] 1.2 `start()` answers `identifier` and `deadline`, and leaves `deadline` out when empty
      (design D4). `WooWrittenCase` reads them back off the write.
  - unit: `WooRequestIntakeTest::testTheAnswerNamesTheCaseNumberAndTheDeadline`, both cases:
    the plain store answers no deadline key at all, a store that stamps a number and a date
    answers both.
- [x] 1.3 `PortalWooRequestController::FIELDS` forwards the new fields.
  - unit: `PortalWooRequestControllerTest::testTheNewAnswersReachTheIntakeAndTheCaseNumberComesBack`,
    which also asserts the number and date come back to the browser.

## 2. Actions

- [x] 2.1 `steps` (with `description`, and `review: true` on the last), `fieldConfigs`
      (no `required`), `draft` and `confirmation` on `startWooVerzoek` (design D1, D3, D4).
  - unit: `PortalContributionProviderTest::testEveryWooFieldSitsInExactlyOneStep` and
    `::testTheWooRequestSavesHalfwayAndNamesTheCaseWhenItIsIn`.
  - Two things portaliq's own resolvers caught, each of which looked right until it was run
    through them: a field left out of every step does not go missing but appears as a
    **titleless step of its own** (portaliq gathers the leftovers into `more`), which is what
    `collectionId` did, so the dossier variant now carries it hidden in step 1; and the answer
    cards need `widget: choices` with the options in `optionsProviders`, because a `choices`
    list written inside a field config is dropped in silence.
- [x] 2.2 `startWooVerzoekAlgemeen` without `attachTo` (design D2), with the `summary` the home
      tile reads (`site-resident-portal-design` REQ-SRPD-006). This completes that
      requirement's third tile, which `site-resident-portal-design` could not deliver.
  - unit: `PortalContributionProviderTest::testTheWooRequestHasADoorWithoutADossier`: one
    route, the same steps, no `attachTo` and no `rowField`, no `collectionId` among its
    fields, and the dossier variant unchanged.

## 3. Validation

- [x] 3.1 `openspec validate site-woo-request-in-steps --strict` passes; the analysers ran over
      the changed files (php -l, phpcs, phpmd both rulesets, phpstan, psalm) and the full
      PHPUnit suite once. No `src/` file changed, so `npm run lint` has nothing of this change
      to read; `check:schema-l10n` is green with the nine new strings in the catalogue.
- [ ] 3.2 Live with `site-multi-step-forms`: a resident starts a request from the home page,
      saves on step 2, comes back, sends, and reads the case number on the confirmation.
