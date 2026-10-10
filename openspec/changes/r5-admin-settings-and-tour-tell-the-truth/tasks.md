# Tasks: r5-admin-settings-and-tour-tell-the-truth

## 1. Search index panel

- [x] 1.1 `searchIndexStatus()` reduces OpenRegister's `lastRun` report to `finishedAt` or `startedAt`, null when none (`lastRunTime()`); the panel shows it as a date and time or "Never".
    - test: `tests/vitest/searchIndexApi.spec.js` (fails on the old pass-through), `tests/vitest/searchIndexTab.spec.js`
- [x] 1.2 Figures stack label over value; the label wraps and is left-aligned.
    - live: `.lane-logs/r5-dq-01-search-index.png`

## 2. Status colours

- [x] 2.1 Every status fill in `src/views/settings/**` pairs with its `-text` ink; text-only marks use the `-text` variable. Prerequisite marks become pill badges.
    - test: `tests/vitest/adminSettingsTruth.spec.js` "pairs every status fill with its -text ink"
    - live: present `rgb(0,84,22)` on `rgb(216,243,218)`, missing `rgb(138,0,0)` on `rgb(255,231,231)`

## 3. Prerequisites

- [x] 3.1 `Prerequisites::check()` reports the running Nextcloud major and `present`; the Nextcloud row carries a badge.
    - test: `PrerequisitesTest::testTheNextcloudRowSaysWhetherThisInstanceIsInRange`, `prerequisitesBlock.spec.js`
- [x] 3.2 `Prerequisites::DISPLAY_NAMES` gives renamed apps their product name; `APPS_OPTIONAL` keys (the `isInstalled()` ids) unchanged.
    - test: `PrerequisitesTest::testARenamedAppShowsItsNewNameAndKeepsItsLookupId`, `prerequisitesBlock.spec.js`

## 4. Case type management

- [x] 4.1 `src/utils/caseTypeColumns.js`: five columns, title first; `CaseTypeList` passes `includeColumns`, an ordered schema copy and a "Draft or published" header.
    - test: `tests/vitest/caseTypeColumns.spec.js` (runs the library's `columnsFromSchema` over the shipped schema)

## 5. Duplicates and empty states

- [x] 5.1 Drop the inner headings of Checklists, AI settings, Term definitions and Mandate matrix.
- [x] 5.2 `EmailSettings::getForm()` renders an empty template; `src/emailSettings.js` and its webpack entry go.
    - test: `tests/Unit/Settings/EmailSettingsTest.php`, `adminSettingsTruth.spec.js`
- [x] 5.3 Tenant onboarding empty state passes its text as `name`.
    - test: `adminSettingsTruth.spec.js`

## 6. Copy

- [x] 6.1 Admin settings headings and the shell title in sentence case, en + nl.
    - test: `adminSettingsTruth.spec.js`
- [x] 6.2 "What shipped with dossiq" counts the register's objects when the ledger is empty (`countObjects()`).
    - test: `tests/vitest/shippedConfigurationEmpty.spec.js`
- [x] 6.3 Tour steps 2, 4 and 7, en + nl.
    - test: `tests/vitest/tourTellsTheTruth.spec.js`; live: `.lane-logs/r5-live-tour.txt`, `r5-dq-08-tour-step7.png`

## 7. Woo refusal grounds

- [x] 7.1 Column labels and `showTitle` on the `WooRefusalGrounds` page, en + nl.
    - test: `tourTellsTheTruth.spec.js`; live: `.lane-logs/r5-dq-07-woo-refusal-grounds.png`

## 8. Gate

- [x] 8.1 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, prettier check, `npm run test:l10n`, vitest. No `Co-Authored-By`.
