# Tasks: portal-case-list-declarations

Tier: V1. Kind: code. Halves: portaliq `cases-my-cases-page`,
`signin-eherkenning-branch`, `identity-ways-in-screens`; dossiq#3152.

## 1. Audiences

- [x] 1.1 (client and citizen done in dossiq#3152, `testAResidentSignedInWithDigidReadsTheirCases`; supplier half 10 Oct: `supplierContribution()` adds `CitizenManifest::caseCollection()`, `PortalCaseDeclarationsTest::testACompanyOnEherkenningReadsItsCasesBesideProcurement`) `getAudiences()` adds `client`; `getContribution()` answers the citizen manifest for `client` and `citizen`, and adds the case collection to the supplier manifest (design D1).
  - unit: `PortalContributionProviderTest` asserts `mijnZaken` for `client`, `citizen` and `supplier`, and none for `inspector`

## 2. My cases

- [x] 2.1 `kind: 'cases'` and `closedField: 'endDate'` on `mijnZaken` (design D2), with `caseTypeField` and `caseTypeSource` (design D5). Test: `tests/Unit/Portal/PortalCaseDeclarationsTest.php::testTheCaseCollectionIsACasesCollection`.
  - unit: the provider test asserts both keys and that `endDate` is projected
- [x] 2.3 `closedField: 'isFinalStatus'` (projected) instead of `endDate`: a withdrawal from the portal lands on a final status with no end date, and three withdrawn Woo requests showed under "Lopend". Test: `tests/Unit/Portal/PortalCaseDeclarationsTest.php::testTheCaseCollectionIsACasesCollection`, `::testAWithdrawnWooRequestIsListedAsClosed`.
- [ ] 2.2 (live pass, decision 139) Live check: portaliq's `GET /portal/api/my-cases` lists a dossiq case for a DigiD dev session, and an ended case lands under Closed (closes dossiq#3152's first half).

## 3. Branch

- [x] 3.1 (`lib/Settings/register.d/75-portal-case-declarations.json`; `PortalCaseDeclarationsTest::testTheBranchAndTheWaysInAreDeclaredOnTheRegister`, nl/en catalogue keys, `check:schema-l10n` 0 uncovered) `portalBranch` on `case` and `portalIdentityKind` on `caseType` in `lib/Settings/register.d/75-portal-case-declarations.json`, with Dutch and English labels (design D3, D4).
  - unit: a schema test asserts both properties; `npm run check:schema-l10n`
- [x] 3.2 (`CitizenManifest::caseCollection()` branchField + projection, not on CITIZEN_CASE_FIELDS so the acknowledgement never quotes it; Woo path: `PortalWooRequestController` reads `branch` from the signed assertion only, `WooRequestIntake` writes `portalBranch` when it is twelve digits: `PortalWooRequestControllerTest::testTheAssertedBranchReachesTheIntake...`, `::testWithoutAnAssertedBranchTheRequestNamesNone`, `WooRequestIntakeTest::testTheBranchAFiledRequestCarriesLandsOnTheCase`; fan-out path: `IntakeFanOutTest::testEveryCaseCarriesTheBranchTheSubmissionWasFiledUnder`. portaliq does not yet put `branch` on the assertion: sibling ask filed) `branchField: 'portalBranch'` on `mijnZaken`, and every path that opens a case from a portal write copies the stamped branch (design D3).
  - unit: one PHPUnit per path, with and without a branch
  - The live check waits on Ruben's broker decision D1: no eHerkenning session carries a branch until a broker vendor is chosen (portaliq row dem-cl-eherkenning-branch).

## 4. Case number

- [x] 4.1 (`CitizenManifest::caseCollection()`; `PortalCaseDeclarationsTest::testTheCaseListNamesItsBranchAndCaseNumberFields`, `::testNoSeededCaseTypeAdmitsTheReferenceWayIn`; editor: `identityKinds()` in `src/services/caseTypePortalSettings.js` and the "How the applicant opens the case" section of `CaseTypePortalWidget.vue`, vitest `caseTypePortalSettings.spec.js`) `referenceField: 'identifier'` on `mijnZaken`; the case type editor shows `reference` disabled with its sentence (design D4).
  - unit: the provider test asserts the key; a vitest asserts the disabled option

## 5. Validation

- [ ] 5.1 `openspec validate portal-case-list-declarations --strict`, `npm run lint`, `composer check:strict` once before push.
