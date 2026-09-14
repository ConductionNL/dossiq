## ADDED Requirements

### Requirement: Every connection is tested from its own screen (REQ-ADMIN-040)

Every screen that configures a connection SHALL offer a test that makes a
real call to the configured endpoint, with a timeout, and prints what came
back. The result SHALL record when it was measured. A connection that has
not been tested SHALL read as not tested and SHALL NOT read as working. A
failing test SHALL name the endpoint, the status and the reason.

#### Scenario: a StUF endpoint is probed before it is relied on
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a configured StUF endpoint
- **WHEN** an administrator tests it
- **THEN** a real call SHALL be made
- **AND** the result SHALL name the endpoint and the status

#### Scenario: an untested connection does not read as working
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a newly saved connection
- **WHEN** an administrator reads the screen
- **THEN** it SHALL read not tested

#### Scenario: a failure says why

- **GIVEN** a connection pointing at an unreachable host
- **WHEN** it is tested
- **THEN** the result SHALL name the reason
- **AND** it SHALL NOT report success

#### Scenario: a stale result is dated

- **GIVEN** a connection tested a week ago
- **WHEN** an administrator reads the screen
- **THEN** the result SHALL carry the moment it was measured
