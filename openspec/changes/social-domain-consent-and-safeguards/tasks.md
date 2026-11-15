# Tasks: social-domain-consent-and-safeguards

## 1. Case type configuration

- [x] 1.1 Case type properties `requiresConsentForSharing`, `requiresSpecialCategory`, `handlingTeamOnly` and `identifyingFields` (property names) on the case type schema; the packages `jeugdwet`, `wmo-melding`, `bijstandsaanvraag` and `re-integratietraject` set them (version bumps).
  - `lib/Settings/register.d/99-case-type-social-safeguards.json` (caseType 1.48.0); the four packages set `requiresConsentForSharing`, `requiresSpecialCategory` and `identifyingFields` (jeugdwet 1.16.0, wmo-melding 1.2.0, bijstandsaanvraag 1.3.0, re-integratietraject 1.4.0). `handlingTeamOnly` is declared but no package sets it until 3.2 enforces it. Test: `tests/Unit/Settings/SocialSafeguardFlagsTest.php`.

## 2. Consent

- [x] 2.1 `src/dialogs/ConsentRecordDialog.vue` writing a `toestemming` object (party, purpose, valid from and until, given by); widget `consents` on CaseDetail in `src/manifest.json`, `visibleWhen` the case type requires consent, row action revoke.
  - `ConsentRecordDialog` (registry modal) writes a `toestemming` with `caseId`, `grantedByBsn`/`grantedByName`, `recipientParties`, `tedoel`, `grantedDate` and `validTo`, the fields CaseTransferConsentGate reads (`src/utils/consentRecord.js`). CaseDetail widget `case-consents` (object-list on `toestemming` filtered by `caseId`, `visibleWhen` `@context.caseType.requiresConsentForSharing`), Add through the dialog, row action Revoke patches `withdrawn: true` (confirmed, hidden once revoked). Test: `tests/vitest/consentRecord.spec.js`.
- [x] 2.2 A generic consent check service (`lib/Service/Consent/ConsentCheck.php`) used by the share paths behind `CaseShareOrganisationDialog` and `CrossDomainLookupDialog`; refuses with 409 and the reason. `tests/Unit/Service/Consent/ConsentCheckTest.php`.
  - Built as an extension of the existing `CaseTransferConsentGate` (`assessSharing`), not a second consent reader: it asks only when the case type sets `requiresConsentForSharing`, reads the same `toestemming` objects a hand-off reads, refuses with "There is no valid consent for this party." (nl: "Er is geen geldige toestemming voor deze partij.") and fails closed when the case type cannot be read. Partner shares and transfers already ran through the gate; federated shares (`CaseSharingService::createFederatedShare`) now do too, answered 409 with the rule. `CrossDomainLookupDialog` looks a person up by BSN under a legal ground inside the organisation and shares no case with an outside party, so it is not a share path. Tests: `tests/Unit/Service/Custody/ConsentForSharingTest.php`, `tests/Unit/Service/FederatedShareNeedsConsentTest.php`.

## 3. Classification and team-only access

- [ ] 3.1 The create form requires a `gdprClassification` when `requiresSpecialCategory`; the case page shows it as a pill; the processing log records the category.
  - Server side done: the case carries `gdprClassification` (the article 9 and 10 categories, case 1.63.0, register 0.20.97), and the intake refuses a case of a type with `requiresSpecialCategory` without it, or with a category the case type's `specialDataCategories` does not list, naming the field (`SpecialCategoryClassification` in `IntakeRequirementsListener`, 422), so the create form, the mail intake and the API are held alike. Tests: `tests/Unit/Service/Intake/SpecialCategoryClassificationTest.php`, `IntakeRequirementsListenerTest::testACaseWithoutItsSpecialCategoryIsStopped`. Open: the field on the create form, the pill on the case page, and the processing-log row for the category.
- [ ] 3.2 `handlingTeamOnly`: the case read rule in OpenRegister RBAC narrows to the case type's handling-team groups (configuration on the schema's authorisation, as `case-access-by-handling-team` did); no admin bypass unless in the team. Check with the OpenRegister RBAC lane if the rule needs an OpenRegister change (decision 156: build it there).

## 4. Anonymised export

- [x] 4.1 The list export gains `anonymised: true`, applying `identifyingFields` (remove, generalise postcode to area, date to year); `tests/Unit/Service/*ExportTest.php` covers it; the export writes a processing-log row.
  - Server side done: `POST /api/cases/anonymised-export` (`CaseAnonymisedExportController`, `AnonymisedCaseExport`) reads the listed cases as the user, removes each case type's `identifyingFields` (postcode to area, date to year, the rest removed, `@self` dropped), refuses when a case type cannot be read, and writes one processing-log row. Tests: `tests/Unit/Service/Export/AnonymisedCaseExportTest.php`, `tests/Unit/Controller/CaseAnonymisedExportControllerTest.php`. Open: the anonymised option on the list's `export` header action in `src/manifest.json`.
  - Frontend: the Cases list's bulk action Export anonymised (`exportAnonymisedSelection`, `src/utils/anonymisedExport.js`) posts the selected cases to the endpoint and saves its answer as CSV; a refusal is shown and nothing falls back to the plain export. A bulk action rather than an entry in the library's Export menu, because that menu streams OpenRegister's export leaf and takes no extra variant; selecting all rows of a filtered list exports that list. Test: `tests/vitest/anonymisedExport.spec.js`.

## 5. Checks

- [ ] 5.1 `tests/e2e/social-domain-consent.spec.ts`: the scenarios of REQ-SDC-CONSENT-01, REQ-SDC-SAFE-01 and REQ-SDC-ANON-01.
- [ ] 5.2 Live pass against board DqSociaalDomein.
- [ ] 5.3 `openspec/parity/capabilities.json` row 14.2: built when 2 to 4 are live.
