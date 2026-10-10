# Tasks: dso-single-intake-path

## 1. One DSO intake path

- [x] 1.1 Red: `tests/Unit/AppInfo/DsoIntakeHasOnePathTest.php` (no DSO intake route, no second intake service)
- [x] 1.2 Retire `lib/Controller/DSOIntakeController.php`, `lib/Service/DsoIntakeService.php`, route `dSOIntake#intake`, their unit tests, and the references in `docs/admin/integrations.md`, `CasePriorityDeclarationTest` and the parity evidence
  - `@spec openspec/changes/dso-single-intake-path/specs/vth-dso-integration/spec.md`

## 2. Complaint case type

- [x] 2.1 Red: `tests/Unit/Service/QuickActionComplaintFindsAShippedCaseTypeTest.php` resolves the complaint against the case types in the shipped register
- [x] 2.2 `QuickActionService::KLACHT_ZAAKTYPE` and the seeded `kcc-qa-klacht-registreren` name `klacht-behandeling`
  - `@spec openspec/changes/dso-single-intake-path/specs/kcc-werkplek-zaaksysteem-bridge/spec.md`

## 3. Verify

- [ ] 3.1 (live pass, decision 139) Live: one verzoek, one case; the integriq handoff and the retired endpoint make none; a complaint gets `klacht-behandeling`
