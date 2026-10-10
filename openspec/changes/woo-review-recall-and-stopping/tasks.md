# Tasks: woo-review-recall-and-stopping

Wave 4. Rows 19.12 and 19.13. Decision D1. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Do not start before** `woo-review-triage` is merged on `development`. A test marked **fails
today** must be run on `origin/development` first and seen red. Do not use a statistics library for
the bound unless one is already in `composer.lock`; the exact binomial bound is a bisection over the
binomial CDF, and the tests pin its values.

## 1. The stopping rule

- [ ] 1.1 Add schema `wooStoppingRule` in `lib/Settings/register.d/88-woo-recall.json` and bump the
  register version (REQ-WRS-001). Give it the private authorization block of `woo-review-triage`
  REQ-WRT-006 and no `x-openregister-hierarchy` key (OpenRegister refuses a parent that references
  another schema, and the schema would fail to import). Add it to the grant table of
  `WooAssessmentAccess::reconcile()` as case assignee read and update, batch reviewers none, and call
  `reconcile()` after the stopping rule route creates it.
  - **fails today**: `tests/Unit/Settings/WooChildSchemasTest.php`
    `testTheStoppingRuleIsPrivateAndDeclaresNoHierarchy`, and
    `tests/Unit/Woo/WooAssessmentAccessTest.php` `testTheStoppingRuleIsGrantedToTheAssigneeOnly`.
- [ ] 1.2 POST `/api/cases/{id}/woo/stopping-rule`, case mutation guard, refused once any document is
  marked. A listener on OpenRegister's updating and deleting events refuses direct writes once review
  started; construct the real event classes in its test (REQ-WRS-001).
  - **fails today**: `tests/Unit/Woo/WooStoppingRuleTest.php` `testARuleIsDeclaredBeforeReview`,
    `testARuleCannotChangeOnceReviewStarted`, `testAnOutOfRangeTargetIsRefused`.
  - Through the caller: `tests/Unit/Controller/WooRecallControllerTest.php`
    `testDeclaringAfterTheFirstMarkingAnswers409`.

## 2. Sample and estimate

- [ ] 2.1 Add `lib/Woo/RecallEstimator.php` with `upperBound(int $k, int $n, float $confidence): float`
  and `estimate(int $found, int $nullSetSize, int $k, int $n, float $confidence): array` per the
  proposal's method (REQ-WRS-002).
  - **fails today** (class absent): `tests/Unit/Woo/RecallEstimatorTest.php`
    `testTheFixtureWithTwoInScope` (0.9615 and 0.8892), `testTheFixtureWithTenInScope` (0.8333 and
    0.7500), `testZeroInScopeHasAPositiveUpperBound`, `testTheBoundRisesWithConfidence`.
  - Class and tests built ahead (`lib/Woo/RecallEstimator.php`, `tests/Unit/Woo/RecallEstimatorTest.php`,
    all four named tests, red first: class absent). Left unticked: its caller is the samples route of 2.2,
    which waits on `woo-review-triage` batches.
- [ ] 2.2 Add schema `wooRecallSample` and POST `/api/cases/{id}/woo/recall/samples` with `{size}`,
  case mutation guard. Draw with a stored seed, create a sample batch through the
  `woo-review-triage` batch service, and record sample judgements apart from the review marking
  (REQ-WRS-002).
  - **fails today**: `tests/Unit/Woo/WooRecallSampleTest.php` `testTheSampleIsDrawnFromTheNullSetOnly`,
    `testTheSameSeedDrawsTheSameDocuments`, `testAJudgementDoesNotChangeTheReviewMarking`,
    `testNoEstimateUntilEveryDocumentIsJudged`.
  - Through the caller: `WooRecallControllerTest::testDrawingRefusesWithoutMutationAccess`.
- [ ] 2.3 A `Recall` section on the Woo case page showing the rule, every sample and estimate with n,
  k, N, found, the confidence and the date, and the gap to the target (REQ-WRS-002).
  - `npm run check:manifest` exits 0.

## 3. Met, recorded

- [ ] 3.1 When a sample completes, compare the lower bound with the rule and write `stoppingRuleMet`
  once, with the internal timeline entry (REQ-WRS-003).
  - **fails today**: `tests/Unit/Woo/WooRecallSampleTest.php` `testMeetingTheRuleIsRecordedOnce`,
    `testBelowTheTargetRecordsNothing`, `testWithoutARuleNothingIsRecorded`.
  - Live check after merge: read the `stoppingRuleMet` record's audit trail entry through the
    OpenRegister API on the dev instance and record it.

## 4. End to end

- [ ] 4.1 e2e `tests/e2e/woo-recall.spec.ts`: declare a rule, mark a document and see the rule
  locked, draw a sample of 20 on a seeded case, judge it, and see the estimate and the met or not
  met line. Cite REQ-WRS-001 to REQ-WRS-003.

## 5. Verify and deliver

- [ ] 5.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 5.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on
  the touched classes. Judge by the `Tests:` line.
- [ ] 5.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires. Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 5.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 5.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 19.12 and 19.13 then read `yes` (build), and `production` only with a
  store release.
