# Tasks: the-support-note-can-be-switched-off

- [x] 1.1 `SupportNoteInitialStateListener` provides `support_note_shown` from `show_support_note`
  - Files: lib/Listener/SupportNoteInitialStateListener.php, lib/AppInfo/Registrar/OnboardingListenerRegistrar.php
  - Test: tests/Unit/Listener/SupportNoteInitialStateListenerTest.php
- [x] 1.2 The frontend holds the note back when it is off
  - Files: src/utils/onboardingOrder.js, src/App.vue
  - Test: tests/vitest/casePageLabelsHistoryAndTourFirst.spec.js
- [x] 1.3 Admin documentation
  - Files: docs/admin/support-note.md
- [x] 1.4 Live: set the switch on the demo instance and see no note on a new user's second load
  - Live pass 2026-11-20 (throwaway rig, NC 34.0.6 with dossiq development 5b6450ef77 and openregister main ce0543d6): tests/e2e/support-note-switch.spec.ts passed 2/2. Switched on, a new user's second load opens the note; switched off, it does not.
