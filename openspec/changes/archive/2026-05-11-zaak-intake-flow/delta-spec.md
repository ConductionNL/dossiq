## Delta Spec: zaak-intake-flow

### Changes to `openspec/specs/zaak-intake-flow/spec.md`

#### REQ-INTAKE-03a: Default Handler from Case Type -- IMPLEMENTED (MVP subset)

- Case type `defaultAssignee` field added to schema
- On case creation (manual or API), if the case type has `defaultAssignee` set, the case `assignee` field is populated automatically
- No round-robin (V1 scope)

#### REQ-INTAKE-03c: No Default Assignee -- IMPLEMENTED

- Cases created without a configured `defaultAssignee` have `assignee` set to `null`
- Dashboard counts these in "unassigned" category (existing behavior)

#### REQ-INTAKE-03d: Assignment Notification -- IMPLEMENTED

- Nextcloud notification sent via `NotificatieService` when a case is auto-assigned
- Notification includes case title and link to case detail

#### REQ-INTAKE-11a: Intake Channel Dropdown -- IMPLEMENTED

- CaseCreateDialog includes optional "Intake Channel" dropdown
- Options: Balie, Telefoon, E-mail, Post, Website, Overig (all translated)

#### REQ-INTAKE-11b: Default Channel for Manual Entry -- IMPLEMENTED

- If no channel selected, defaults to `manual`
- Case info panel shows "Bron: Handmatig"

#### REQ-INTAKE-08d: Intake Channel on Case Detail -- IMPLEMENTED

- Case Information card in CaseDetail shows intake channel with translated label
