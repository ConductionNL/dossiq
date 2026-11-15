# Tasks: r6-dossiq-titles-related-cases-requests

## 1. Page titles

- [x] 1.1 `src/utils/pageTitle.js` builds "<page> - <server title>"; `main.js` installs it as a router `afterEach`.
    - test: `tests/vitest/pageTitle.spec.js` (real vue-router; wiring read from main.js)
- [x] 1.2 Manifest titles "My Work", "Workflow Board", "LHS Recommendations", "LHS Recommendation" in sentence case; key "LHS recommendation" added en + nl.

## 2. Admin settings

- [x] 2.1 Case type list: `#header` slot with a visually hidden `<h3>`; `addLabel` "Add case type".
    - test: `tests/vitest/caseTypeListHeading.spec.js`
- [x] 2.2 `Prerequisites::DISPLAY_NAMES` names every declared app by product; lookup ids unchanged.
    - test: `PrerequisitesTest::testEveryAppRowShowsAProductNameAndKeepsItsLookupId`

## 3. Related cases card

- [x] 3.1 `CasePlannedWidget` passes `showObjects: false`, adds a Parent case section, opens a case row on click.
- [x] 3.2 `relationSections()` leaves out rows without a readable title; `CaseRelationService` resolves legacy titles.
    - test: `tests/vitest/relatedCasesCard.spec.js`, `CaseRelationServiceTest::testALegacyRelationCarriesTheFarCaseTitle`
- [x] 3.3 English value of "Case Type" is "Case type".

## 4. Request errors

- [x] 4.1 `CasePlanPanel` asks OpenRegister for a plan only when `mayHoldOpenRegisterPlan()` says it can hold one.
    - test: `tests/vitest/casePlanPanel.spec.js` "asks OpenRegister only when it can hold a plan"
- [x] 4.2 `PreferencesController` accepts `cn_page_view:<pageId>`.
    - test: `PreferencesControllerTest::testThePageViewKeyIsAccepted`, `testAColonOutsideThePageViewPrefixIsRefused`

## 5. Verification

- [x] 5.1 Live on :8099: tab titles, admin heading, product names, Related cases card, network log without the two errors.
