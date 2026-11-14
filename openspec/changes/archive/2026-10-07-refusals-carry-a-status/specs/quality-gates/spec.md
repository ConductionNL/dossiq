## ADDED Requirements

### Requirement: Catch-and-return-null sites are counted and ratcheted (REQ-QG-CRN-1)

A structural test SHALL list every `catch (\Throwable)` under `lib/Service/`
that returns `null` or `[]` within three statements and SHALL fail on any
site absent from a reason-bearing allowlist, on any allowlist entry without
a site, and when the count exceeds the recorded ceiling. Each entry SHALL
name its class: `refusal`, `degradation` or `read-miss`.

#### Scenario: A new swallowing catch fails the build
@e2e exclude structural; covered by ServiceCatchReturnsNullTest over a fixture file

- **GIVEN** a service method with a new `catch (\Throwable) { return null; }`
- **WHEN** the structural test runs
- **THEN** it SHALL fail naming the file and method

#### Scenario: The ceiling only goes down
@e2e exclude structural; same test, ceiling branch

- **GIVEN** an allowlist ceiling of 47 and 48 sites
- **WHEN** the test runs
- **THEN** it SHALL fail

### Requirement: A rule that refuses a write answers with a status (REQ-QG-CRN-2)

Every allowlisted site of class `refusal` SHALL be converted to throw a
typed exception that the controller translates to a 4xx with `{message,
error}`, `error` naming the rule, and its allowlist entry removed. Every
controller unit test of a guarded method SHALL assert the status code for
the pass and the refusal.

#### Scenario: A refused transition tells the caller
@e2e tests/e2e/refusal-status.spec.ts

- **GIVEN** a case in a status with no transition to Closed
- **WHEN** the transition is requested over the API
- **THEN** the response SHALL be 409 with `error` naming the rule
- **AND** the case status SHALL be unchanged

#### Scenario: Degradation stays, and says so
@e2e exclude covered by the allowlist entries of class degradation and the warning-log assertion in their unit tests

- **GIVEN** the engine is absent
- **WHEN** a timer call runs
- **THEN** a warning SHALL be logged naming the engine
- **AND** the domain flow SHALL continue on case data
