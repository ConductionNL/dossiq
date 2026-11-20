# first-load-onboarding Delta: the-support-note-can-be-switched-off

**Status**: draft
**Scope**: the support note CnAppRoot opens once per user.

## ADDED Requirements

### Requirement: An administrator can switch the support note off (REQ-FLO-002)

dossiq SHALL read the app config `show_support_note`. When it is `false`, `0`, `no` or `off`, the support note SHALL NOT open for any user. Any other value, and no value, SHALL leave the note's behaviour unchanged (REQ-FLO-001).

#### Scenario: A demo instance never shows the note
@e2e exclude changes an instance-wide setting other suites read; covered by SupportNoteInitialStateListenerTest and casePageLabelsHistoryAndTourFirst.spec.js
- **GIVEN** `occ config:app:set dossiq show_support_note --value=false`
- **WHEN** a user who finished the tour loads dossiq
- **THEN** the support note does not open

#### Scenario: Unset, nothing changes
@e2e exclude covered by SupportNoteInitialStateListenerTest
- **GIVEN** no `show_support_note`
- **WHEN** a user who finished the tour loads dossiq for the first time after it
- **THEN** the support note opens once, as before
