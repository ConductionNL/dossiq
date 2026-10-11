# Tasks: woo-request-scoped-access

Wave 2. Row 12.28, dossiq's half. Decision D1. Kind: code. Build rules: `openspec/woo-build-rules.md`.

Before starting, read on openregister `development` at that moment: `ObjectSharingService`,
`ObjectOwnershipService`, `ObjectScopeResolver` and `PermissionBit` (signatures as the proposal
quotes them, or changed), and whether `reviewer-owns-their-decisions` is merged. Write both answers
in the PR body. A test marked **fails today** must be run on `origin/development` first and seen
red; put the failing line in the PR body. Double OpenRegister classes with
`environmentAwareDouble()` after reading their real signatures; `grant()` throws
`NotAuthorizedException`, it does not return null.

## 1. The assessment is private

- [x] 1.1 `wooDocumentAssessment` carries `scope: private` (read/create/update `authenticated`,
  delete `dossiq-coordinators`), version 1.2.0 (REQ-WSA-001).
  - done: `lib/Settings/register.d/83-woo-document-assessment.json`; digests regenerated, register 0.20.30.
    Decision 185: the shipped JSON is procedure configuration, proven by Newman (1.2), no unit test.
- [ ] 1.2 Prove the block reaches the instance and is enforced (REQ-WSA-001).
  - Newman `tests/newman/woo-request-scoped-access.postman_collection.json`, folder `private`:
    read the schema and assert `authorization.scope` is `private`; as an unassigned user, list
    assessments and read one by uuid; assert none listed and the read refused. Cite REQ-WSA-001.

## 2. Grants follow the assignment

- [x] 2.1 Decision 182: generic, not `WooAssessmentAccess`. `lib/Service/Access/CaseReviewerAccess.php`
  (`declarationFor`, `reconcile`, `reconcileAll`, `mayChange`, `isSupervisor`) over the case type's
  `reviewerAccess` (`register.d/89-case-reviewer-access.json`), and `lib/Service/Access/ObjectReadGrants.php`
  (the OpenRegister side: `listGrants`/`grant`/`revoke` on `ObjectSharingService`, `setOwnerGroup` on
  `ObjectOwnershipService`, resolved by name, `unavailable` without them). The Woo case type declares
  it (case type object 1.3.0). REQ-CRA-001/002.
  - done: `tests/Unit/Service/Access/CaseReviewerAccessTest.php` (8), `ObjectReadGrantsTest.php` (5:
    read bit only, owner skipped, former reader revoked, group/inherited grants kept, second run
    changes nothing, refusal counted, unavailable).
- [ ] 2.2 `bulkUpsert()` reconciles the case after a create (REQ-CRA-002): done,
  `WOODocumentAssessmentServiceTest::testACreatedAssessmentReconcilesTheCase`. Open: the batch
  creation of `woo-review-triage` (L8's `ReviewBatches`, not on development) calls
  `CaseReviewerAccess::reconcile()` when it lands.
- [ ] 2.3 Add `lib/Listener/WooCaseReassignGuardListener.php` on OpenRegister's `ObjectUpdatingEvent`
  (refuse per REQ-WSA-005 with `setErrors()` and `stopPropagation()`, as `CaseDeleteGuardListener`
  does on delete) and on `ObjectUpdatedEvent` (run `reconcile()`). Read both event classes in
  openregister first; `ObjectUpdatingEvent` has `getNewObject()` and `getOldObject()`. Register
  both in `Application.php` (REQ-WSA-005).
  - **fails today**: `tests/Unit/Listener/WooCaseReassignGuardListenerTest.php`
    `testANonCoordinatorIsRefused`, `testACoordinatorMayAndGrantsMove`,
    `testACaseWithoutAssessmentsIsUntouched`, constructing the real event classes.
  - Through the caller: Newman folder `reassign` in the collection of 1.2: a PUT on the case by the
    handler answers the refusal, a PUT by a coordinator succeeds and the former handler then reads
    no assessment.

## 3. A reviewer edits only their own

- [x] 3.1 `bulkUpsert()` refuses per document a change to an assessment someone else made unless
  the caller supervises (`CaseReviewerAccess::mayChange`), error `owned-by-another-reviewer` without
  author or verdict; the existing-assessment lookup runs unscoped (REQ-CRA-003).
  - done: `WOODocumentAssessmentServiceTest::testASecondReviewerCannotOverwrite`.
- [ ] 3.2 Apply the same rule to `saveRedactionProposal()` and the redaction proposal review
  (REQ-WSA-003).
  - **fails today**: `tests/Unit/Service/WOOAnonymisationAssistServiceTest.php`
    `testAnotherReviewersProposalCannotBeChanged`; through the caller
    `WOOAssessmentControllerTest::testReviewRedactionProposalRefusesAnotherReviewersProposal`.
- [ ] 3.3 Newman folder `ownership` in the collection of 1.2: reviewer A assesses a document,
  reviewer B posts another verdict for it through `/api/cases/{id}/woo/assessment` and through the
  OpenRegister object API; both are refused and the verdict is unchanged; a coordinator's change is
  stored. Cite REQ-WSA-003.

## 4. Hiding another reviewer's verdict

- [ ] 4.1 Decision 182: hiding is the case type's `reviewerAccess.hideOthers` (default off), not an
  app setting; `reconcile()` honours it (`CaseReviewerAccessTest::testHidingLeavesOnlyTheAssigneeAsReader`).
  Open: `getOutstanding()` counting a document with an unreadable assessment as assessed.
- [ ] 4.2 The case's Woo assessment section (added by `woo-case-screens-and-objections`) shows a
  document that is not outstanding and has no readable assessment as "Assessed by another
  reviewer", with no classification, grounds or author (REQ-WSA-004). If that section is not on
  `development`, add the state to whatever lists the case's documents with their assessment, and say
  which in the PR body.
  - vitest on that component: `testAHiddenVerdictShowsAsAssessedByAnotherReviewer`.
  - e2e `tests/e2e/woo-blind-review.spec.ts`: hiding on, two reviewer users, four documents
    assessed by the first; the second sees ten documents, four of them "Assessed by another
    reviewer". Cite REQ-WSA-004.

## 5. Existing assessments and the redaction half

- [x] 5.1 Generic `POST /api/cases/reviewer-access/reconcile` (administrator only, fails closed)
  over `CaseReviewerAccess::reconcileAll()` (REQ-CRA-004).
  - done: `lib/Controller/CaseReviewerAccessController.php`,
    `tests/Unit/Controller/CaseReviewerAccessControllerTest.php` (non-admin refused, sums, 503 unavailable).
    Newman folder `reconcile` stays with 1.2.
- [ ] 5.2 If `openregister/reviewer-owns-their-decisions` is merged: declare `x-openregister-review`
  on the document schema per REQ-WSA-007, add the key to `SchemaSlugMap::SCHEMA_ANNOTATION_KEYS`,
  and prove it reaches the instance (REQ-WSA-007).
  - unit `tests/Unit/Settings/DocumentSchemaReviewTest.php` `testTheDocumentSchemaDeclaresDeciderOwnership`;
    Newman folder `redaction`: reviewer B's PATCH on reviewer A's entity relation answers 403
    `decision-owned`.
  - If it is not merged, do not declare the key: say in the PR body that 12.28's redaction half
    waits on openregister issue #4394, and leave this task open.

## 6. Live

- [ ] 6.1 Live check after merge on the dev instance: run the reconcile route, then as an unassigned
  user, as the assignee and as a coordinator, read one assessment of a Woo case through the
  OpenRegister API, and record the three answers in the PR.

## 7. Verify and deliver

- [ ] 7.1 `TMPDIR` set to a sibling directory beside the clone, never inside it.
- [ ] 7.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `npx vitest run` on the touched specs. Judge PHPUnit by the `Tests:` line.
- [ ] 7.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires (read `package.json`).
  Then hydra's `scripts/run-hydra-gates.sh --base origin/development`, and count the gates that ran.
- [ ] 7.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 7.5 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By` on any
  commit. Done means merged on `development` with CI green. Row 12.28 reads `yes` (build) only with
  `openregister/reviewer-owns-their-decisions` merged too, and `production` only with store
  releases of both. Say in the PR body that an administrator must run the reconcile route once
  after upgrading.
