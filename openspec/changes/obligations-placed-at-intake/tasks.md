# Tasks: obligations-placed-at-intake

### Task 1: Register
- **spec_ref**: `openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them`
- **files**: `lib/Settings/dossiq_register.json`
- [x] `case.intakeObligations`, `obligation.holderName`, `settledBySubject`, `settledTrust`, `caseType.obligationKinds[].whenUnmet`; register 0.20.87

### Task 2: Place on receipt
- **spec_ref**: `openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them`
- **files**: `lib/Service/Obligations/IntakeObligations.php`, `lib/Listener/IntakeObligationListener.php`, `lib/AppInfo/Registrar/WorkflowListenerRegistrar.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/Obligations/IntakeObligationsTest.php`

### Task 3: Settle from the portal
- **spec_ref**: `openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal`
- **files**: `lib/Controller/PortalObligationController.php`, `appinfo/routes.php`, `lib/Service/Obligations/ObligationService.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/Obligations/IntakeObligationsTest.php`, `tests/Unit/Controller/PortalObligationControllerTest.php`

### Task 4: Refusal and lapse
- **spec_ref**: `openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-refused-or-lapsed-intake-obligation-follows-its-kinds-rule`
- **files**: `lib/Service/Obligations/ObligationDeclaration.php`, `lib/BackgroundJob/IntakeObligationLapseJob.php`, `appinfo/info.xml`
- [x] Implement
- [x] Test: `tests/Unit/Service/Obligations/IntakeObligationsTest.php`

### Task 5: Live check
- [ ] With portaliq's resident-identity-in-forms T04 on a live instance: submit, sign as a second DigiD, refuse, and a lapse.
