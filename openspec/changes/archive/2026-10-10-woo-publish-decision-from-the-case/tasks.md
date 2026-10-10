# Tasks: woo-publish-decision-from-the-case

Tier: V1. Kind: code. Rows: opencatalogi `int-case-system`, `woo-from-case`.

## 1. The decision keeps what is written to it

- [ ] 1.1 (live pass, decision 139: needs a running OpenRegister; moot for the code since 1.2 declares the property, and the repair in 3.1 marks a decision without `wooPublication` as ready) Prove whether OpenRegister keeps an undeclared `wooPublication` on the
  `decision` schema today: save one, read it back through `ObjectService::find()`,
  and record the answer in the PR body.
  - verification: the read-back output pasted in the PR
- [x] 1.2 Declare `wooPublication`, `wooSummary`, `weigeringsgronden`,
  `assessmentCount` and `decidedBy` on `decision` in
  `lib/Settings/dossiq_register.json`; bump the schema version. Already on
  `development` (decision schema 1.1.0 declares all five, read 2026-09-30);
  the schema test is added in 5.1.
  - unit: a schema test asserting the five properties and their types
  - `composer check:strict` exit 0

## 2. The server finds the Woo decision

- [x] 2.1 `WooPublicationService`: resolve the case's Woo decision when no
  `decisionId` is given (design D-1); 409 `no_woo_decision` and
  `several_woo_decisions`.
  - unit: `WooPublicationServiceTest` covers none, one and two decisions
  - `@spec openspec/changes/woo-publish-decision-from-the-case/tasks.md#task-2.1`
- [x] 2.2 `WOOAssessmentController::publishDecision` and `withdrawPublication`
  pass an absent `decisionId` through instead of answering 400.
  - unit: `WOOAssessmentControllerTest` asserts the status of every branch;
    `WOOAssessmentControllerAuthorizationTest` stays green unchanged

## 3. The case carries its publication state

- [x] 3.1 (writers and schema in `register.d/82` and `WooCaseLedger`; repair step `lib/Repair/BackfillWooPublicationState.php`, post-migration, completion key `woo_publication_state_backfill`, test `tests/Unit/Repair/BackfillWooPublicationStateTest.php`; the `occ maintenance:repair` check is 3.2) Declare `wooPublicationStatus` and `wooPublicationUrl` on `case`
  (design D-2); write them from `assembleDecision()`, `publish()` and
  `withdraw()`; add an idempotent repair step for existing Woo cases, registered
  post-migration with a persisted version key (ADR-106).
  - unit: one test per writer asserting the case write; one repair test run twice
- [ ] 3.2 (live pass, decision 139) `occ maintenance:repair` on a dev instance with one published Woo case:
    the case reads `published` with its url

## 4. The surface

- [x] 4.1 `src/manifest.json` `#CaseDetail`: header actions `woo-publish` and
  `woo-withdraw` (design D-4); the two fields on the Data tab's core section.
  - `npm run check:manifest` exit 0
  - vitest: both actions present with their url, method and `visibleWhen`
- [ ] 4.2 (written; the run is the live pass, decision 139) `tests/e2e/woo-publish-from-the-case.spec.ts`: a handler publishes a
  ready Woo decision, sees the link, withdraws it; citing the scenarios below.
  - `npx playwright test tests/e2e/woo-publish-from-the-case.spec.ts` exit 0
- [x] 4.3 Delete `src/services/wooPublicationApi.js` if nothing imports it after
  4.1, or import it from the handler that needs it.
  - `npm run lint` exit 0

## 5. The Woo journey (hydra `woo-citizen-journey` C6, C3)

Added 2026-09-30. Design D-5 to D-8.

- [x] 5.1 `buildPayload()` writes `publicationKind`, `wooCategory`, `caseReference`, `period` and `publicationDate` (D-5);
  `withdraw()` writes `depublicationDate`; documents become files on the
  publication (D-6).
  - unit: `WooPublicationServiceTest` asserts the payload, the file attach on
    the publication id and the withdraw field; a schema test pins the five
    decision properties
- [x] 5.2 After a publish, append the publication to `wooRequest.collectionId`
  once, `addedBy: dossiq` (D-7).
  - unit: appended once; republish adds nothing; no collection, no write; a
    collection failure is logged and the publish still answers published
- [x] 5.3 `mijnZaken` carries `wooPublicationUrl`, and the citizen contribution
  declares the change rule `dossiq.wooRequest.published` on it (D-8).
  - unit: `PortalContributionProviderTest` asserts the rule for `citizen` and `client`
- [x] 5.4 The assessment schema ships and publishing reads the case's informatieobjecten (D-9).
  - unit: `WooPublishOnTheRealRegisterTest` takes its configuration from the merged register and the slug map, assesses an uploaded document and publishes it with its file
- [x] 5.5 Every Woo write and seed row fits its schema by value (D-10).
  - unit: `WooWritesMatchTheRealSchemasTest` (seed rows, decision, assessment, request case and case objects, publish and withdraw)

## 6. The Woo screens (2026-10-01, design D-11)

- [x] 6.1 `woo-publish` and `woo-withdraw` show only to the assignee, decided locally and ahead of every endpoint-gated action; publishing asks first.
  - vitest: `wooPublishHeaderBar.spec.js` renders the real headerActions through the built `CnActionButtons` with every endpoint request held open
- [x] 6.2 After publishing, the header links to the publication (`woo-publication-open`).
  - vitest: `wooPublishHeaderBar.spec.js` reads the entry's `href`
- [x] 6.3 The refusal sentences are translated server side.
  - unit: `WOOAssessmentControllerTest::testARefusalIsTranslatedForTheHeaderAction`
  - `npm run test:l10n` exit 0
