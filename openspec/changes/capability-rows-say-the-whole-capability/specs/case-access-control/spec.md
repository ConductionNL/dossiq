# case-access-control

## ADDED Requirements

### Requirement: Each application role states what its members may do (REQ-CRS-14)

Every role dossiq provisions SHALL state which acts it allows. The admin settings SHALL show each role with that list, and an act a role does not allow SHALL be refused to a member who holds only that role.

Rows: `13.1` (dossiq matrix).

#### Scenario: An auditor reads but does not change
- **GIVEN** a user whose only role is auditors
- **WHEN** they try to move a case to another status
- **THEN** the move is refused and the admin settings list reading the audit log as what auditors may do

### Requirement: A handler grants and withdraws access to one case from its page (REQ-CRS-43)

The case page SHALL let its handler grant a user or group access to that case only, and withdraw it. A withdrawn grant SHALL refuse that user's next read of the case.

Rows: `13.3` (dossiq matrix).

#### Scenario: A grant is withdrawn
- **GIVEN** a case shared with the user jan
- **WHEN** the handler withdraws the grant
- **THEN** jan's next read of the case is refused
