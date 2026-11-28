# Tasks: zuiddrecht-staff-have-seen-the-tour

- [x] 1.1 `userPreferences` for the nine staff members in the Zuiddrecht fragment
  - Files: lib/Settings/example-sets/zuiddrecht.json
  - Test: tests/Unit/Settings/ZuiddrechtStaffHaveSeenTheTourTest.php
- [x] 1.2 Live install on a dev instance once OpenRegister carries REQ-EXS-024
  - Done 25 Nov 2026 on a sandbox instance (openregister main): the install reports `User preference dossiq/<uid>/pref_walkthrough_completed_version: created` for the nine staff members, and `oc_preferences` holds 1.2.0 for each, equal to the manifest version.
