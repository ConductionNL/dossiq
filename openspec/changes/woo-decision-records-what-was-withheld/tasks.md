# Tasks: woo-decision-records-what-was-withheld

Wave 3. Supports row 6.16. Decisions D1, D3, D9 and D12. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Do not start before** `opencatalogi/woo-decision-shows-what-was-withheld` is merged on opencatalogi
`development` and `woo-request-takes-over-from-opencatalogi` on dossiq `development`. Then read
`OCA\OpenCatalogi\Service\Woo\WithheldDocuments::record()` there and copy its signature, entry keys,
answer keys and refusal reasons into the contract test with their source line. If either is not
merged, stop and say which. A test marked **fails today** must be run on `origin/development` first
and seen red; put the failing line in the PR body.

## 1. The entries

- [ ] 1.1 Add `lib/Woo/WithheldEntries.php` with `build(string $caseId): array`: one
  `{position, grounds}` per `niet_openbaar` assessment, `position` from `WooDecisionDrafts::wooDecision()`
  (REQ-WRW-001).
  - unit `tests/Unit/Woo/WithheldEntriesTest.php`: `testOnlyWithheldDocumentsAreListed`,
    `testPositionsMatchTheInventoryNumbers`, `testNoKeyButPositionAndGrounds` (the fixture carries
    file ids, hashes and document references on every document and assessment), `testNoTitleIsEverSent`.

## 2. The call

- [ ] 2.1 Call `record()` from `WooPublicationService::publish()` after the publication is created or
  updated, resolving `WithheldDocuments` from the container only when opencatalogi is enabled and
  the class exists; answer `withheldRecord`; never roll back the publication (REQ-WRW-001,
  REQ-WRW-002).
  - **fails today**: `tests/Unit/Service/WooPublicationServiceTest.php`
    `testPublishRecordsTheWithheldDocuments` (asserts the exact arguments: publication id, the two
    entries, source `dossiq`), `testARefusedEntryIsReportedAndThePublicationStands`,
    `testAThrowingRecordLeavesThePublication`, `testAnOlderOpencatalogiRecordsNothing`.
  - App absent: `testWithoutOpencatalogiRecordIsNeverCalled`.
  - Through the caller: `tests/Unit/Controller/WOOAssessmentControllerTest.php`
    `testPublishDecisionAnswersTheWithheldRecord`.
- [ ] 2.2 Call `record($publicationId, [], 'dossiq')` from `withdraw()` (REQ-WRW-001).
  - **fails today**: `WooPublicationServiceTest::testWithdrawClearsTheWithheldList`.
- [ ] 2.3 Contract test `tests/Unit/Woo/WithheldDocumentsContractTest.php`
  `testTheCallMatchesOpencatalogisSignature`: the method name, the three parameters, the entry keys
  and the answer keys as literals copied from opencatalogi's class with its source line, built on the
  real class when it is autoloadable and on that signature otherwise (REQ-WRW-001). Name
  opencatalogi's `WithheldDocumentsRecordTest::testTheContractKeysMatchBothSides` in the PR body.

## 3. The handler sees the outcome

- [ ] 3.1 The case's Woo publication section shows a `withheldRecord` refusal or `not-recorded` in one
  sentence, with the positions and codes refused (REQ-WRW-001, REQ-WRW-002).
  - vitest on that component: `testARefusedWithheldRecordIsShown`, `testNotRecordedSaysWhy`.

## 4. Live

- [ ] 4.1 Live check after merge on the dev instance with opencatalogi carrying
  `woo-decision-shows-what-was-withheld`: publish a Woo case with two withheld documents, then read
  the `withheldDocument` objects for that publication as an administrator, and paste both in the PR
  body. Set `appstoreenabled=false` before any `occ upgrade` on a mounted clone.

## 5. Verify and deliver

- [ ] 5.1 `TMPDIR` set to a sibling directory beside the clone, never inside it.
- [ ] 5.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `npx vitest run` on the touched specs. Judge PHPUnit by the `Tests:` line.
- [ ] 5.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires (read `package.json`).
  Then hydra's `scripts/run-hydra-gates.sh --base origin/development`, and count the gates that ran.
- [ ] 5.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 5.5 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By` on any
  commit. Done means merged on `development` with CI green. Row 6.16 closes in portaliq, and reads
  `production` only once store releases carry every half.
