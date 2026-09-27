# Tasks: woo-publish-decision-from-the-case

Tier: V1. Kind: code. Rows: opencatalogi `int-case-system`, `woo-from-case`.

## 1. The decision keeps what is written to it

- [ ] 1.1 Prove whether OpenRegister keeps an undeclared `wooPublication` on the
  `decision` schema today: save one, read it back through `ObjectService::find()`,
  and record the answer in the PR body.
  - verification: the read-back output pasted in the PR
- [ ] 1.2 Declare `wooPublication`, `wooSummary`, `weigeringsgronden`,
  `assessmentCount` and `decidedBy` on `decision` in
  `lib/Settings/dossiq_register.json`; bump the schema version.
  - unit: a schema test asserting the five properties and their types
  - `composer check:strict` exit 0

## 2. The server finds the Woo decision

- [ ] 2.1 `WooPublicationService`: resolve the case's Woo decision when no
  `decisionId` is given (design D-1); 409 `no_woo_decision` and
  `several_woo_decisions`.
  - unit: `WooPublicationServiceTest` covers none, one and two decisions
  - `@spec openspec/changes/woo-publish-decision-from-the-case/tasks.md#task-2.1`
- [ ] 2.2 `WOOAssessmentController::publishDecision` and `withdrawPublication`
  pass an absent `decisionId` through instead of answering 400.
  - unit: `WOOAssessmentControllerTest` asserts the status of every branch;
    `WOOAssessmentControllerAuthorizationTest` stays green unchanged

## 3. The case carries its publication state

- [ ] 3.1 Declare `wooPublicationStatus` and `wooPublicationUrl` on `case`
  (design D-2); write them from `assembleDecision()`, `publish()` and
  `withdraw()`; add an idempotent repair step for existing Woo cases, registered
  post-migration with a persisted version key (ADR-106).
  - unit: one test per writer asserting the case write; one repair test run twice
  - `occ maintenance:repair` on a dev instance with one published Woo case:
    the case reads `published` with its url

## 4. The surface

- [ ] 4.1 `src/manifest.json` `#CaseDetail`: header actions `woo-publish` and
  `woo-withdraw` (design D-4); the two fields on the Data tab's core section.
  - `npm run check:manifest` exit 0
  - vitest: both actions present with their url, method and `visibleWhen`
- [ ] 4.2 `tests/e2e/woo-publish-from-the-case.spec.ts`: a handler publishes a
  ready Woo decision, sees the link, withdraws it; citing the scenarios below.
  - `npx playwright test tests/e2e/woo-publish-from-the-case.spec.ts` exit 0
- [ ] 4.3 Delete `src/services/wooPublicationApi.js` if nothing imports it after
  4.1, or import it from the handler that needs it.
  - `npm run lint` exit 0
