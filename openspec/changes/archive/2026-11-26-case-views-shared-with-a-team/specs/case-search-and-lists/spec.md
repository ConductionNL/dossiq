## ADDED Requirements

### Requirement: A saved case view can be shared with a team (REQ-CSL-SHARE-01)

A user who saves a view on a case, task or queue list SHALL be able to share it with one or more of the teams or departments they belong to, read-only or editable. Members of those groups SHALL see the view in their saved views with the owner's columns and label, and only the owner, an editor or an administrator SHALL change or delete it.

#### Scenario: A team lead shares a view with the handling desk

- **GIVEN** a team lead in group "Team Vergunningen" saves the view "Te beoordelen deze week"
- **WHEN** they share it read-only with "Team Vergunningen"
- **THEN** every member SHALL see the view under shared views with the same columns
- **AND** a member SHALL NOT be able to overwrite it
- @e2e tests/e2e/case-views-shared.spec.ts

#### Scenario: Share targets are the reader's own teams

- **GIVEN** a handler in two teams
- **WHEN** they open the share picker
- **THEN** the picker SHALL offer those two teams and SHALL NOT list groups the handler is not in
- @e2e tests/e2e/case-views-shared.spec.ts
