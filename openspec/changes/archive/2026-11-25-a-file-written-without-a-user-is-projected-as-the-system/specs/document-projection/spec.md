# document-projection Delta: a-file-written-without-a-user-is-projected-as-the-system

**Status**: draft
**Scope**: file events in a case folder that arrive without a signed-in user.

## ADDED Requirements

### Requirement: A file written without a signed-in user is projected as the system (REQ-DPR-006)

When a file in a case folder is created, written, renamed or deleted and no user is signed in (a command-line install, a repair step, a background job), dossiq SHALL project it as the system, so the file gets its `informatieobject` and its join to the case as it would for a handler. A file a signed-in user writes SHALL be projected as that user. A file outside the register tree SHALL NOT be projected and SHALL NOT cause any elevation.

#### Scenario: An example set installed from the command line gets its documents
@e2e exclude a command-line install has no browser; covered by CaseFolderNodeListenerTest through the listener's own entry point

- **GIVEN** no user is signed in
- **WHEN** a file is written into a case folder
- **THEN** its projection runs as the system
- **AND** no "does not have permission to 'create' objects" error is logged

#### Scenario: A handler's upload is not elevated
@e2e exclude covered by CaseFolderNodeListenerTest

- **GIVEN** a signed-in handler
- **WHEN** they upload a file into a case folder
- **THEN** its projection runs as that handler
