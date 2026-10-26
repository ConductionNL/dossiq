# case-management Delta: notification-email-opt-in

## ADDED Requirements

### Requirement: A person can switch e-mail on per notification

dossiq's per-person notifications SHALL declare e-mail as an opt-in channel. The personal notification settings SHALL draw one column per channel the platform names for the notifications, e-mail unchecked until the person checks it, and a change SHALL write the row's full channel set.

#### Scenario: Switch e-mail on
- GIVEN "A case is assigned to me" with Nextcloud on and e-mail off
- WHEN the person checks its "Per e-mail" cell
- THEN the preference stored is enabled with channels nc-notification and email
