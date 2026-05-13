## Architecture

### Data Model Changes

**caseType schema** -- add field:
- `defaultAssignee` (string, optional): Nextcloud user UID or group ID for automatic assignment

**case schema** -- add field:
- `intakeChannel` (string, enum, optional): One of `manual`, `balie`, `telefoon`, `email`, `post`, `website`, `overig`, `zgw-api`

### Frontend Changes

**CaseCreateDialog.vue**:
- Add `NcSelect` dropdown for intake channel with translated labels
- On submit: if case type has `defaultAssignee`, set `assignee` on the new case
- Default intake channel to `manual` if not selected

**CaseDetail.vue**:
- In the Case Information card, display intake channel with translated label (e.g., "Bron: Telefoon")

### Backend Changes

**ZgwZrcRulesService.php**:
- On ZGW API case creation, set `intakeChannel = 'zgw-api'`
- If case type has `defaultAssignee`, apply it to the case

### Notification

- Use `OCA\Procest\Service\NotificatieService` to send Nextcloud notification on assignment
- Notification text: "Nieuwe zaak toegewezen: [title]" with link to case detail

## Decisions

1. **No round-robin in MVP**: Round-robin assignment (REQ-INTAKE-03b) is V1 scope -- MVP only handles static defaultAssignee
2. **Intake channel stored on case**: Rather than only in audit trail, store as a first-class field for easy querying/reporting
3. **Notification via existing service**: Reuse NotificatieService rather than creating a new notification mechanism
