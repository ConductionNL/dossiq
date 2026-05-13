# Delta: prometheus-metrics

## ADDED Requirements
### Requirement: REQ-PROM — 002a (ENHANCED)
The system SHALL satisfy the behaviour described as "requirement".

- Added `nextcloud_version` label to `procest_info` gauge

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-PROM — 002b (ENHANCED)
The system SHALL satisfy the behaviour described as "requirement".

- `procest_up` gauge now reflects actual database health (was hardcoded to 1)

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-PROM — 003e (IMPLEMENTED)
The system SHALL satisfy the behaviour described as "requirement".

- Added `procest_cases_created_today` gauge metric

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-PROM — 004d (IMPLEMENTED)
The system SHALL satisfy the behaviour described as "requirement".

- Added OpenRegister dependency check to health endpoint
- OpenRegister unavailable sets overall status to "error"

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-PROM — 009a/b (IMPLEMENTED)
The system SHALL satisfy the behaviour described as "requirement".

- Added APCu caching for all expensive metric queries
- Case counts and task counts cached for 30 seconds
- Overdue counts cached for 60 seconds
- Graceful fallback when APCu is unavailable

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour
