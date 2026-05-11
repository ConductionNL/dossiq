# Delta: admin-settings

## ADDED Requirements
### Requirement: REQ-ADMIN-009 — Implemented
The system SHALL satisfy the behaviour described as "REQ-ADMIN-009 — Implemented".

- Created ResultsTab.vue with result type CRUD
- Archival action (retain/destroy) via radio buttons
- Retention period input in ISO 8601 format
- Human-readable period display (e.g., "20 years")

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-ADMIN-010 — Implemented
The system SHALL satisfy the behaviour described as "REQ-ADMIN-010 — Implemented".

- Created RolesTab.vue with role type CRUD
- Generic role dropdown with 8 options: initiator, handler, advisor, decision_maker, stakeholder, coordinator, contact, co_initiator
- Multiple role types can share the same generic role

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-ADMIN-011 — Implemented
The system SHALL satisfy the behaviour described as "REQ-ADMIN-011 — Implemented".

- Created PropertiesTab.vue with property definition CRUD
- Format dropdown: text, number, date, datetime
- Max length field (number input)
- Required at status dropdown populated from case type's status types
- Optional/required toggle via status selection

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-ADMIN-004 — Enhanced
The system SHALL satisfy the behaviour described as "REQ-ADMIN-004 — Enhanced".

- CaseTypeDetail tabs expanded from 2 (General, Statuses) to 5 (General, Statuses, Results, Roles, Properties)

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour
