# Tasks: woo-refusal-grounds-list

Wave 1. Rows 12.29 and 13.28. Kind: mixed (seed plus code). Decisions D3 and D12. Build rules: `openspec/woo-build-rules.md`.

## 1. Settle the list against the law (BLOCKS EVERY OTHER TASK)

- [x] 1.1 **Stop here. Do not decide alone.** A building agent does not settle 15 versus 19 (done 2026-10-09: D-2 settled by Ruben van der Linde, decision 133, copied into design.md from `~/memcap-work/build-all/dossiq/woo-refusal-grounds-D2-draft.md`.)
  versus 12 versus 10. Open the PR as a draft with only `design.md` D-1, and ask the coordinator
  for a person who will settle the list against the consolidated Woo on wetten.overheid.nl. Ruben
  named "someone with the law in hand" in D3. That person writes D-2: the date, the source
  version, the final list with code, article, paragraph, letter and label, and the mapping of
  each old list to it (REQ-WRG-001).
  - Done when D-2 is filled in and the person who settled it is named in it. No seed, code or
    test task starts before that.

## 2. The list in dossiq's register

- [x] 2.1 Add schema `wooRefusalGround` in a new `lib/Settings/register.d/NN-woo-refusal-grounds.json` (`register.d/84-woo-refusal-grounds.json`: 21 citable grounds and 4 group nodes, with `kind` and `citable`; writes for admin and `beheerders`.)
  with the REQ-WRG-002 properties and the settled seed objects. Write is for admin plus the named
  group, and read is for authenticated users and the system. Bump the register version (REQ-WRG-002,
  REQ-WRG-003).
  - unit `tests/Unit/Settings/WooRefusalGroundsSeedTest.php`:
    `testTheSeedIsExactlyTheSettledList`, which compares against a fixture copied from D-2, and
    `testEveryNarrowerGroundNamesAnExistingParent`.
  - unit: `testAHandlerCannotWriteAGround`. This asserts the schema's authorization block against
    the real register JSON, through `tests/Support/RealSchemaValidator`.
- [x] 2.2 Refuse delete of a cited ground. Add a listener on OpenRegister's `ObjectDeletingEvent` (`lib/Listener/WooRefusalGroundDeleteGuard.php`, registered in ObjectListenerRegistrar; also holds a ground a decision cites, and refuses when the citations cannot be read.)
  for `wooRefusalGround` that refuses when any assessment or decision cites the code (REQ-WRG-002).
  - **fails today** (the listener does not exist): `tests/Unit/Listener/WooRefusalGroundDeleteGuardTest.php`
    `testACitedGroundCannotBeDeleted`. Use the real event class's signature.

## 3. The administrator's page

- [x] 3.1 Add a settings page `WooRefusalGrounds` in `src/manifest.json` (route (`WooRefusalGrounds` index sorted by code with the broader ground as a column, and `WooRefusalGroundDetail` with the audit History tab; no board on identity.conduction.nl/screens draws this page. The e2e is written, not run.)
  `/settings/woo-refusal-grounds`, `permission: admin`). It shows the grounds as a tree with add,
  edit and retire, and a history tab that reads OpenRegister's audit trail for the object
  (REQ-WRG-003, REQ-WRG-004).
  - `npm run check:manifest` exits 0.
  - e2e `tests/e2e/woo-refusal-grounds.spec.ts`: an admin adds 5.1.2.e.1 under 5.1.2.e and edits
    a label, then sees both in the history. A non-admin gets no page.
- [ ] 3.2 (not run: needs the live instance after merge) Prove the audit trail records a change, through the API (REQ-WRG-004).
  - Use a live check, not a unit test, because the audit trail is OpenRegister's. After merge on
    the dev instance, PATCH a label and read `/api/objects/{register}/{schema}/{id}/audit-trails`.
    Record the entry in the PR or issue.

## 4. dossiq validates against the list

- [x] 4.1 Add `lib/Woo/WooRefusalGrounds.php` with `list()` and `byCode()` (design D-3). It reads (answers twelve keys: the ten plus `kind` and `citable`.)
  as the system and throws `WooRefusalGroundsUnavailable` on a failed read (REQ-WRG-007).
  - unit `tests/Unit/Woo/WooRefusalGroundsTest.php`: `testItAnswersActiveGroundsWithAllTenKeys`,
    `testRetiredGroundsOnlyOnRequest`, `testAFailedReadThrowsAndNeverAnswersEmpty`. Build the
    object service double from the real `ObjectService` method signatures.
- [x] 4.2 `WOODocumentAssessmentService::validate()` uses `WooRefusalGrounds::byCode()`. Remove (a group node is refused as not citable; `bulkAssess` answers 422 when an assessment is refused and the refusal status, 503, when the list cannot be read.)
  `VALID_WEIGERINGSGRONDEN` and the template's `weigeringsgronden` array. An unreadable list
  refuses with 503 (REQ-WRG-005).
  - **fails today**: `tests/Unit/Service/WOODocumentAssessmentServiceTest.php`
    `testARetiredGroundIsRefused` and `testAnUnreadableListRefusesTheAssessment`.
  - Through the caller: `tests/Unit/Controller/WOOAssessmentControllerTest.php`
    `testBulkAssessWithAnUnknownGroundAnswers422`.
- [x] 4.3 Update `openspec/specs/woo-case-type/spec.md` through this change's delta, so the (delta `specs/woo-case-type/spec.md`, folded at archive time.)
  "Mandatory weigeringsgrond" scenario points at the list instead of naming 12 grounds. Do this
  at archive time through `/opsx-sync`, not by hand-editing the main spec.

## 5. Map what is stored

- [x] 5.1 Add repair step `lib/Repair/MapWooRefusalGroundCodes.php`, using the D-2 mapping. It (per-object marker `groundsListVersion`, which new assessments and decisions also carry; `groundsUnmapped` lists what stays.)
  is idempotent, flags unmapped codes, and lists them (REQ-WRG-006).
  - unit `tests/Unit/Repair/MapWooRefusalGroundCodesTest.php`: `testAnUnambiguousCodeIsMapped`,
    `testAnAmbiguousCodeIsFlaggedAndKept`, `testASecondRunChangesNothing`.

## 6. The snapshot

- [x] 6.1 Add `composer snapshot:refusal-grounds` and `composer check:refusal-grounds-snapshot`, (`tools/refusal-grounds-snapshot.php`; the check runs second in `check:strict`.)
  and add the check to `check:strict`. Commit the generated
  `lib/Settings/woo-refusal-grounds.snapshot.json` (REQ-WRG-008).
  - unit `tests/Unit/Settings/RefusalGroundsSnapshotTest.php` `testTheSnapshotMatchesTheSeed`.
    It fails when one label differs.
- [x] 6.2 In the PR body, name the consumer changes that vendor the snapshot and call
  `WooRefusalGrounds::list()`: `filinq/grondslagen-read-from-dossiq` and the opencatalogi change
  that retires `WooService::WEIGERINGSGRONDEN`. Each must test its side of the call: the method
  name, the arguments, the ten return keys, and the snapshot fallback when dossiq is absent.

## 7. Verify and deliver

- [ ] 7.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 7.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter`
  on the touched classes and read the `Tests:` line.
- [ ] 7.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then
  `npm run lint`, `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg that `code-quality.yml` requires. Then run hydra's
  `scripts/run-hydra-gates.sh --base origin/development` and count the gates that ran.
- [ ] 7.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 7.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means
  merged on `development` with CI green. Rows 12.29 and 13.28 then read `yes` (build), and
  `production` only with a store release.
