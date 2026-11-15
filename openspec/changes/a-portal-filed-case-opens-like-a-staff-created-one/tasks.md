# Tasks: a-portal-filed-case-opens-like-a-staff-created-one

- [x] 1.1 `CaseOpeningDefaults`: empty opening fields from the case type
  - Files: lib/Service/Intake/CaseOpeningDefaults.php
- [x] 1.2 `CaseCreationAnnouncer`: dispatch the withheld `ObjectCreatedEvent` inside a system operation
  - Files: lib/Service/Intake/CaseCreationAnnouncer.php
- [x] 1.3 `SystemCaseStore::write()` applies both; `WooRequestIntake` hands it the announcer
  - Files: lib/Service/Intake/SystemCaseStore.php, lib/Woo/WooRequestIntake.php
- [x] 1.4 Test through the real caller, `WooRequestIntake::start()`, ending at the real acknowledgement listener
  - Test: tests/Unit/Woo/PortalFiledCaseOpensLikeAStaffCaseTest.php
- [ ] 1.5 Live: file a Woo request on :8080 after the upgrade and see the receipt mail and the case in the `behandelaars` list (needs the dossiq #3865 upgrade there)
