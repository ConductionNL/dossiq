# Tasks: woo-review-reports

Wave 3. Rows 16.10 and 16.11. Decisions D1 and D9. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Do not start before** `woo-request-corpus-collection` is merged on `development`. A test marked
**fails today** must be run on `origin/development` first and seen red.

## 1. The switches

- [ ] 1.1 Add the three settings to dossiq's admin settings (`SettingsService` and the admin settings
  page), defaults false and empty. Refuse switching `wooReviewerThroughputReport` on without an
  existing reader group (REQ-WRR-001).
  - **fails today**: `tests/Unit/Service/SettingsServiceTest.php`
    `testBothWooReportsAreOffOnAFreshInstall`, `testThroughputNeedsAReaderGroup`.
  - The admin settings component is not added to the vue-router (gate admin-router).

## 2. Throughput per reviewer per day

- [ ] 2.1 Add `lib/Woo/WooThroughputReport.php`. Read openregister on `development`: if
  `adhoc-aggregation-suite`'s multi-field `groupBy` is merged, call the `grouped` aggregation with
  `groupBy: [assessedBy, assessedAt:day, classification]`. If not, group a paged
  `searchObjects` of the scoped assessments in PHP, and say which in the PR body (REQ-WRR-002).
  - **fails today** (class absent): `tests/Unit/Woo/WooThroughputReportTest.php`
    `testAssessmentsAreCountedPerReviewerPerDay` (the scenario fixture),
    `testTheDayIsTheInstanceTimeZoneDay` (an assessment at 23:30 UTC on a CET instance lands on
    the next day).
- [ ] 2.2 Add `WooReportController::throughput()` on GET `/api/woo/reports/throughput` with
  `#[NoAdminRequired]` and, in the body, the switch check and the group membership check. Write an
  audit entry per read through OpenRegister's audit trail or Nextcloud's `IEventLogger`, whichever
  dossiq already uses for reads of personal data (REQ-WRR-001, REQ-WRR-002).
  - **fails today**: `tests/Unit/Controller/WooReportControllerTest.php`
    `testOffAnswers403`, `testAReviewerOutsideTheGroupIsRefused`,
    `testAnAdministratorOutsideTheGroupIsRefused`, `testAReadIsAudited`.
  - Gates no-admin-idor and semantic-auth must pass on this controller.
- [ ] 2.3 A `Woo reports` screen (manifest page, visible only when a switch is on) with the throughput
  table and a CSV download (REQ-WRR-002).
  - `npm run check:manifest` exits 0.
  - unit `WooReportControllerTest::testTheCsvHasTheSameRows`.

## 3. Mail header fields

- [ ] 3.1 In the gather add, parse mail headers for `.eml` (Nextcloud's or a PHP mime parser already
  in `composer.lock`; do not add a new dependency without saying so) and read integriq mail items'
  header fields from its row. Store `provenance.mail`; mark unreadable headers (REQ-WRR-003).
  - **fails today**: `tests/Unit/Controller/WooSourcesControllerTest.php`
    `testAnEmlKeepsItsSenderAndRecipients`, `testUnreadableHeadersAreMarked`, using a real `.eml`
    fixture in `tests/Fixtures/mail/`.
  - Validate the provenance against the real document schema with `RealSchemaValidator`.

## 4. The parties report

- [ ] 4.1 Add `lib/Woo/WooPartiesReport.php` and `WooReportController::parties(string $id)` on GET
  `/api/cases/{id}/woo/reports/parties`, case read guard and switch check in the body, CSV beside it
  (REQ-WRR-004).
  - **fails today**: `tests/Unit/Woo/WooPartiesReportTest.php`
    `testCountsPerSenderRecipientAndDomain` (the scenario fixture), `testUnreadableIsCounted`.
  - Through the caller: `WooReportControllerTest::testPartiesRefusesWithoutCaseAccess`,
    `testPartiesOffAnswers403`.

## 5. End to end and live

- [ ] 5.1 e2e `tests/e2e/woo-review-reports.spec.ts`: with both switches off the screen is absent;
  switch on with a reader group; a member sees the throughput table, a non-member gets no screen;
  the parties report lists the fixture mails. Cite REQ-WRR-001 to REQ-WRR-004.
- [ ] 5.2 Live check after merge on the dev instance: switch on, read both reports for one Woo case,
  and read the audit entry of the throughput read. Record them.

## 6. Verify and deliver

- [ ] 6.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 6.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on
  the touched classes. Judge by the `Tests:` line.
- [ ] 6.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires. Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 6.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 6.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 16.10 and 16.11 then read `yes` (build) as opt-in features, and
  `production` only with a store release.
