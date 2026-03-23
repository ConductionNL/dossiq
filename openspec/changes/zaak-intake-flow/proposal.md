## Why

The zaak intake flow is partially implemented -- manual case creation and ZGW API intake work, but several MVP requirements are missing: automatic behandelaar assignment (REQ-INTAKE-03), intake channel selection on manual forms (REQ-INTAKE-11), and assignment notifications. 61% of tenders require intake capabilities, making this a critical gap.

## What Changes

- **REQ-INTAKE-03a**: Add `defaultAssignee` field to case type schema and auto-assign cases on creation
- **REQ-INTAKE-03c/d**: Handle cases with no default assignee; send Nextcloud notification on assignment
- **REQ-INTAKE-11a/b**: Add intake channel dropdown to CaseCreateDialog with options (Balie, Telefoon, E-mail, Post, Website, Overig)
- **REQ-INTAKE-08a/d**: Record intake source metadata (channel, creator) on case creation; display intake channel on case detail

## Capabilities

### New Capabilities
- `intake-auto-assignment`: Automatic case handler assignment based on case type defaultAssignee configuration
- `intake-channel-tracking`: Record and display the intake channel for manual case creation

### Modified Capabilities
- `procest-case-creation`: CaseCreateDialog extended with intake channel field and auto-assignment logic
- `procest-case-detail`: Case info panel shows intake channel source

## Impact

- **Schema**: `procest_register.json` -- add `defaultAssignee` to caseType schema, add `intakeChannel` to case schema
- **Frontend**: `CaseCreateDialog.vue` -- add intake channel dropdown, auto-assign on submit
- **Frontend**: `CaseDetail.vue` -- display intake channel in case info
- **Backend**: `ZgwZrcRulesService.php` -- apply defaultAssignee on API intake
- **Dependencies**: Nextcloud notification API for assignment notifications
