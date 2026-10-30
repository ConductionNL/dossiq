# Tasks: social-domain-consent-and-safeguards

## 1. Case type configuration

- [ ] 1.1 Case type properties `requiresConsentForSharing`, `requiresSpecialCategory`, `handlingTeamOnly` and `identifyingFields` (property names) on the case type schema; the packages `jeugdwet`, `wmo-melding`, `bijstandsaanvraag` and `re-integratietraject` set them (version bumps).

## 2. Consent

- [ ] 2.1 `src/dialogs/ConsentRecordDialog.vue` writing a `toestemming` object (party, purpose, valid from and until, given by); widget `consents` on CaseDetail in `src/manifest.json`, `visibleWhen` the case type requires consent, row action revoke.
- [ ] 2.2 A generic consent check service (`lib/Service/Consent/ConsentCheck.php`) used by the share paths behind `CaseShareOrganisationDialog` and `CrossDomainLookupDialog`; refuses with 409 and the reason. `tests/Unit/Service/Consent/ConsentCheckTest.php`.

## 3. Classification and team-only access

- [ ] 3.1 The create form requires a `gdprClassification` when `requiresSpecialCategory`; the case page shows it as a pill; the processing log records the category.
- [ ] 3.2 `handlingTeamOnly`: the case read rule in OpenRegister RBAC narrows to the case type's handling-team groups (configuration on the schema's authorisation, as `case-access-by-handling-team` did); no admin bypass unless in the team. Check with the OpenRegister RBAC lane if the rule needs an OpenRegister change (decision 156: build it there).

## 4. Anonymised export

- [ ] 4.1 The list export gains `anonymised: true`, applying `identifyingFields` (remove, generalise postcode to area, date to year); `tests/Unit/Service/*ExportTest.php` covers it; the export writes a processing-log row.

## 5. Checks

- [ ] 5.1 `tests/e2e/social-domain-consent.spec.ts`: the scenarios of REQ-SDC-CONSENT-01, REQ-SDC-SAFE-01 and REQ-SDC-ANON-01.
- [ ] 5.2 Live pass against board DqSociaalDomein.
- [ ] 5.3 `openspec/parity/capabilities.json` row 14.2: built when 2 to 4 are live.
