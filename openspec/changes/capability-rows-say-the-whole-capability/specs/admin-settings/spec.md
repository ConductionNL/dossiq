# admin-settings

## ADDED Requirements

### Requirement: An administrator exports the filtered audit log (REQ-CRS-09)

An administrator SHALL be able to export the audit log as a file, holding the rows the current filters show. The export SHALL be refused to a user who may not read the audit log.

Rows: `10.5` (dossiq matrix).

#### Scenario: An export holds the filtered rows
- **GIVEN** an audit log filtered to one user and one week
- **WHEN** an administrator exports it
- **THEN** the file holds only that user's entries of that week

### Requirement: An administrator creates and edits an object type (REQ-CRS-12)

An administrator SHALL be able to create a new object type and change the properties of an existing one. A new object type SHALL be offered when a handler links an object to a case.

Rows: `11.17` (dossiq matrix).

#### Scenario: A new object type is offered on the case
- **GIVEN** an administrator who creates the object type "Tree"
- **WHEN** a handler links an object to a case
- **THEN** Tree is offered as the object type

### Requirement: An administrator edits the text of each notification (REQ-CRS-13)

The admin settings SHALL let an administrator change the text of each notification dossiq sends. Saving SHALL store the text, and the next notification of that kind SHALL carry it.

Rows: `11.24` (dossiq matrix).

#### Scenario: A changed assignment notice
- **GIVEN** an administrator who changes the text of the case assigned notification
- **WHEN** a case is assigned to a handler
- **THEN** the handler's notification reads the new text

### Requirement: An administrator manages departments and assigns users to roles in the app (REQ-CRS-22)

The admin settings SHALL let an administrator create and edit departments and assign a Nextcloud user to a role within a department. An assignment SHALL take effect on that user's next request.

Rows: `11.19` (dossiq matrix).

#### Scenario: A user joins a department role
- **GIVEN** a department "Vergunningen" and the user bea
- **WHEN** an administrator assigns bea the role handler in Vergunningen
- **THEN** bea is listed under that role
