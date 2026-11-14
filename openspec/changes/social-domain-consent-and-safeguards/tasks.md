# Tasks: social-domain-consent-and-safeguards

## 1. Case type configuration

- [x] 1.1 Case type properties `requiresConsentForSharing`, `requiresSpecialCategory`, `handlingTeamOnly` and `identifyingFields` (property names) on the case type schema; the packages `jeugdwet`, `wmo-melding`, `bijstandsaanvraag` and `re-integratietraject` set them (version bumps).
  - `lib/Settings/register.d/99-case-type-social-safeguards.json` (caseType 1.48.0); the four packages set `requiresConsentForSharing`, `requiresSpecialCategory` and `identifyingFields` (jeugdwet 1.16.0, wmo-melding 1.2.0, bijstandsaanvraag 1.3.0, re-integratietraject 1.4.0). `handlingTeamOnly` is declared but no package sets it until 3.2 enforces it. Test: `tests/Unit/Settings/SocialSafeguardFlagsTest.php`.

## 2. Consent

- [ ] 2.1 `src/dialogs/ConsentRecordDialog.vue` writing a `toestemming` object (party, purpose, valid from and until, given by); widget `consents` on CaseDetail in `src/manifest.json`, `visibleWhen` the case type requires consent, row action revoke.
- [x] 2.2 A generic consent check service (`lib/Service/Consent/ConsentCheck.php`) used by the share paths behind `CaseShareOrganisationDialog` and `CrossDomainLookupDialog`; refuses with 409 and the reason. `tests/Unit/Service/Consent/ConsentCheckTest.php`.
  - Built as an extension of the existing `CaseTransferConsentGate` (`assessSharing`), not a second consent reader: it asks only when the case type sets `requiresConsentForSharing`, reads the same `toestemming` objects a hand-off reads, refuses with "There is no valid consent for this party." (nl: "Er is geen geldige toestemming voor deze partij.") and fails closed when the case type cannot be read. Partner shares and transfers already ran through the gate; federated shares (`CaseSharingService::createFederatedShare`) now do too, answered 409 with the rule. `CrossDomainLookupDialog` looks a person up by BSN under a legal ground inside the organisation and shares no case with an outside party, so it is not a share path. Tests: `tests/Unit/Service/Custody/ConsentForSharingTest.php`, `tests/Unit/Service/FederatedShareNeedsConsentTest.php`.

## 3. Classification and team-only access

- [ ] 3.1 The create form requires a `gdprClassification` when `requiresSpecialCategory`; the case page shows it as a pill; the processing log records the category.
- [ ] 3.2 `handlingTeamOnly`: the case read rule in OpenRegister RBAC narrows to the case type's handling-team groups (configuration on the schema's authorisation, as `case-access-by-handling-team` did); no admin bypass unless in the team. Check with the OpenRegister RBAC lane if the rule needs an OpenRegister change (decision 156: build it there).

## 4. Anonymised export

- [ ] 4.1 The list export gains `anonymised: true`, applying `identifyingFields` (remove, generalise postcode to area, date to year); `tests/Unit/Service/*ExportTest.php` covers it; the export writes a processing-log row.
  - Server side done: `POST /api/cases/anonymised-export` (`CaseAnonymisedExportController`, `AnonymisedCaseExport`) reads the listed cases as the user, removes each case type's `identifyingFields` (postcode to area, date to year, the rest removed, `@self` dropped), refuses when a case type cannot be read, and writes one processing-log row. Tests: `tests/Unit/Service/Export/AnonymisedCaseExportTest.php`, `tests/Unit/Controller/CaseAnonymisedExportControllerTest.php`. Open: the anonymised option on the list's `export` header action in `src/manifest.json`.

## 5. Checks

- [ ] 5.1 `tests/e2e/social-domain-consent.spec.ts`: the scenarios of REQ-SDC-CONSENT-01, REQ-SDC-SAFE-01 and REQ-SDC-ANON-01.
- [ ] 5.2 Live pass against board DqSociaalDomein.
- [ ] 5.3 `openspec/parity/capabilities.json` row 14.2: built when 2 to 4 are live.
