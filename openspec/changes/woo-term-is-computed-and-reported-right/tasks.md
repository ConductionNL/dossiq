# Tasks: woo-term-is-computed-and-reported-right

Wave 1. Statutory: Woo art. 4.4 and Awt art. 1. Rows 10.9 and 16.2. Kind: code. Build rules: `openspec/woo-build-rules.md`.

A test marked **fails today** must be run on `origin/development` first and seen red. Put the
failing line in the PR body. Dates in tests come from a fixed clock and a fixed calendar, never
from `today`.

## 1. The case deadline is rolled

- [x] 1.1 Stop the case `deadline` coming from the unrolled calculation. Remove
  `x-openregister-calculations.deadline` from `components.schemas.case` in
  `lib/Settings/dossiq_register.json`. Declare `deadlineBeforeRoll` (date, readOnly) on the case
  and bump the register version. First establish, against the real OpenRegister on
  `origin/development`, whether a materialised calculation runs before or after
  `ObjectCreatingEvent` listeners. If it runs after, removing it is required, not optional. Write
  the answer in the PR body (REQ-WTR-001).
  - Done. Answer (openregister `development`, `CalculationOnSaveListener`): the calculation is a
    listener on `ObjectCreatingEvent`/`ObjectUpdatingEvent` at the default priority 0, so it runs
    BEFORE dossiq's listener at -100, and dossiq's `setModifiedData` is merged after both and after
    `enforceReadOnlyOnUpdate`. The listener would win on a save, but the calculation still writes
    the unrolled date on `occ openregister:rematerialise-calculations` and on every update without
    the listener, so it is removed. Case schema 1.38.0, register 0.20.21, `deadlineBeforeRoll`
    declared, digests recorded.
- [x] 1.2 Generalise `CaseInheritedDeadlineListener` (or add `CaseDeadlineListener` beside it) so
  every case whose effective case type declares `processingDeadline` gets
  `deadline = rollTermEndFor(startDate + term, definitie)` and `deadlineBeforeRoll`. It keeps the
  `rollToWorkingDay: false` switch (REQ-WTR-001).
  - **fails today**: `tests/Unit/Listener/CaseDeadlineListenerTest.php`
    `testAWooTermEndingOnChristmasRollsToMonday` (start 2026-11-27, expects 2026-12-28 and
    `deadlineBeforeRoll` 2026-12-25) and `testKingsDayRollsToTheNextDay`. Build the event with
    the real `ObjectCreatingEvent` and `ObjectEntity` classes, or with an environment-aware double
    that has their real signatures (`getObject()`, `setModifiedData()`). Do not use an
    anonymous stub.
  - Done: renamed to `lib/Listener/CaseDeadlineListener.php`, covering every case type with an
    effective term; an update keeps a stored deadline unless the start date or case type moved.
    `tests/Unit/Listener/CaseDeadlineListenerTest.php` (15 tests, real events, real timer on the
    statutory fallback calendar). Fails today: the class does not exist on `origin/development`,
    and the old listener left a Woo case (own term) to the unrolled calculation (2026-12-25).
- [x] 1.3 Mirror the term instance onto the case. When a Woo case's statutory term instance's
  `endDateCurrent` changes (extension, pause, resume), the case `deadline` is written to match.
  Wire it at `TermijnService::saveTermInstance()` / `updateTermijnInstance()`, the one write path
  (REQ-WTR-001).
  - unit `tests/Unit/Service/TermijnServiceTest.php`
    `testACaseDeadlineFollowsItsTermAfterAPause`.
  - Done: `lib/Service/Termijn/CaseDeadlineFollower.php` called from `saveTermInstance()` and
    `updateTermijnInstance()`. `deadline` is readOnly, so the follower records the date in
    `CaseDeadlineMirror` and saves the case with its stored values; `CaseDeadlineListener` takes
    the date on that save. Only a statutory instance moves the case
    (`testAnInternalTargetDoesNotMoveTheCaseDeadline`).

## 2. API end dates are rolled

- [x] 2.1 `DeadlineExtensionService::applyExtension()` rolls `newEndDate` with
  `TermijnTimerService::rollTermEndFor()` and the instance's definition before the ceiling check
  and before it stores the date. It stores the supplied date as `endDateBeforeRoll`. Declare
  `endDateBeforeRoll` on `deadlineInstance` in `register.d/60-termijnbewaking.json` (REQ-WTR-002).
  - **fails today**: `tests/Unit/Service/DeadlineExtensionLimitTest.php`
    `testASundayEndDateIsRolledToMonday`.
  - Through the caller: `tests/Unit/Controller/TermijnControllerTest.php`
    `testVerlengRollsTheEndDate`, with POST to `verleng` and a Sunday `newEinddatum`.
  - Done: rolled in `applyExtension()` before the ceiling check and the days impact;
    `endDateBeforeRoll` declared in `register.d/60-termijnbewaking.json`. Tests in
    `DeadlineExtensionLimitTest` and, through the route, `TermijnControllerContractTest`
    `testVerlengRollsTheEndDate` (the controller test class is the contract test).

## 3. The Woo extension goes through termijn#verleng

- [x] 3.1 Rewrite `WOODeadlineService::extendDeadline(caseId, reason)`. It resolves the case's Woo
  term instance (`TermijnService::getTermijnInstanceForZaak()`), computes
  `endDateCurrent + extensionPeriod` (read from the case type, P14D), and calls
  `DeadlineExtensionService::requestExtension(instanceId, reason, newEnd)`. It returns
  `{caseId, previousDeadline, deadline, extensionReason, countExtensions}`. It no longer writes
  `expectedResolution`, `deadlineVerlengd` or `verdagingReden`. `resolveWarningDeadline()` reads
  `deadline` only (REQ-WTR-003).
  - **fails today**: `tests/Unit/Service/WOODeadlineServiceTest.php`
    `testTheExtensionMovesTheTermInstanceAndTheCase` and
    `testTheCapHoldsWhenTheCaseDropsUndeclaredKeys`. For the second test, use a register double
    that drops every key the real case schema does not declare
    (`tests/Support/RealSchemaValidator`).
  - Through the caller: `tests/Unit/Controller/WOOAssessmentControllerTest.php`
    `testASecondWooExtensionAnswers409`.
  - Done: the statutory instance is picked from `instancesForCase()` (the latest instance can be
    another kind); the period comes from `TermDeclarationReader` (P14D, 14 when undeclared). The
    ceiling refusal is `ExtensionCeilingReachedException` (a `RuntimeException`, same message), which
    the Woo service turns into `RefusedException` `woo-one-extension` (409). No term engine: refused,
    nothing written. Tests run over the real `TermijnService`, `DeadlineExtensionService` and the
    seeded Woo definition.
- [x] 3.2 Make sure the seeded Woo term definition caps extensions at one. Check
  `register.d/81-woo-verzoek.json` and the `TermijnDefinitie` the Woo case type binds. Replace
  `resolveMaxExtensions()`'s reflection on `definitieCache` with a real read of the definition. A
  missing definition counts as one, which is the safe value (REQ-WTR-003).
  - unit: `tests/Unit/Service/DeadlineExtensionLimitTest.php`
    `testTheWooDefinitionAllowsOneExtension`.
  - Done: `termijnbewaking_seed_data.json` `td-woo-verzoek` has `countExtensions` 1 (the test reads
    the seed). The reflection is gone: `TermijnService::getTermijnDefinitieById()` reads the row
    through `TermDefinitions::byId()`; a missing definition counts as one
    (`testAMissingDefinitionCountsAsOne`).
- [x] 3.3 Search `src/` and `lib/` for readers of `expectedResolution`, `deadlineVerlengd` and
  `verdagingReden`, and move each one to `deadline` or the term instance. List them in the PR body.
  - Done. Readers found: `WOODeadlineService::resolveWarningDeadline()` (now reads `deadline`) and
    `extendDeadline()` itself. Nothing in `src/`. Left as is: `calculate()` returns an
    `expectedResolution` key (a return value, no caller in `lib/`), and the Woo case type's
    `verdagingReden` property definition (`register.d/81-woo-verzoek.json`), a handler-entered case
    property, not a key this route wrote.

## 4. The report groups by the real case type

- [x] 4.1 `DeadlineReportingService::aggregateByType()` resolves the case type through
  `deadlineInstance.case`, then the definition, and otherwise uses `unresolved` with the ids
  listed. Do the case lookup in one batched search, not one search per row (REQ-WTR-004).
  - **fails today**: `tests/Unit/Service/DeadlineReportingServiceTest.php`
    `testWooTermsAreGroupedUnderTheWooCaseType` and `testNoTermIsReportedUnderUnknown`.
  - Through the caller: `tests/Unit/Controller/DeadlineReportingControllerTest.php`
    `testTheQuarterlyRouteGroupsByCaseType`.
  - Done: `caseTypesOf()` reads cases, case types and definitions in three `_ids`-restricted
    searches; keys are the case type identifier (what a definition names), with `title` beside it;
    `metadata.unresolvedInstances` lists the rest. The through-the-caller test lives in
    `DeadlineReportingControllerContractTest` (the controller's test class). The old fixture gave
    each instance an undeclared `caseType`; it now reads through real case rows.
- [x] 4.2 Add `received`, `met`, `missed`, `running`, `suspended` and `metShare` per type.
  Existing keys keep their meaning (REQ-WTR-005).
  - unit: `testMetMissedRunningAndSuspendedAreCounted` with the four-term fixture of the scenario.
  - If the dashboard widget reading `/api/termijn/reports/kwartaal` shows per-type rows, it shows
    the case type title. Test this with vitest only if the widget changes.
  - Done: the six keys per type; `TdQuarterlyWidget.vue` shows `row.title` (falls back to the key),
    `tests/vitest/tdQuarterlyWidgetCaseTypeTitle.spec.js`.

## 5. End to end and live

- [ ] 5.1 `tests/e2e/woo-term-rolls.spec.ts`: create a Woo case through the API with a start date
  whose term ends on a configured holiday, and read the `deadline` back. Extend it once and then
  twice. Cite REQ-WTR-001 and REQ-WTR-003. (not run: written as `tests/e2e/woo-term-rolls.spec.ts`;
  no live instance in this lane, disk under 1 GB)
- [ ] 5.2 Live check after merge on the dev instance: one Woo case started on 2026-11-27 through
  the portal intake. Read `deadline` and the term instance's `endDateCurrent` through the
  OpenRegister API, and record both. This proves the removed calculation no longer overwrites the
  listener. (not run: live, after merge)

## 6. Verify and deliver

- [x] 6.1 `TMPDIR` set to a sibling directory beside the clone, never inside it.
- [x] 6.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter`
  on the touched classes and judge by the `Tests:` line. `TermijnService` and
  `DeadlineExtensionService` are central, so run the full unit suite once before push.
- [x] 6.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then
  `npm run lint`, `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg that `code-quality.yml` requires. Then run hydra's
  `scripts/run-hydra-gates.sh --base origin/development` and count the gates that ran.
- [x] 6.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured. (not projectable: no coverage driver here, and `development`'s last green push run, 35823727270 on 23 Sep, published no clover artifact. The PR body lists the test class that pins each added class instead.)
- [ ] 6.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means
  merged on `development` with CI green. Rows 10.9 and 16.2 then read `yes` (build), and
  `production` only with a store release.
