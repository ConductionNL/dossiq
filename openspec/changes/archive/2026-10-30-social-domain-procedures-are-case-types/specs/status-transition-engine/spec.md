# status-transition-engine (delta)

## ADDED Requirements

### Requirement: A required-field guard sees a sensitive answer without showing it (REQ-STE-SENS-001)

When a case does not carry the guarded field at the top level or in `properties`, the required-field
guard SHALL check the case's `sensitiveProperties` read as the system, and SHALL answer only
whether the field is answered: the guard's details and message SHALL carry the field name and
never the value. A case that cannot be read SHALL fail the guard.

#### Scenario: A klantmanager outside dossiq-sensitive completes the tests
- **GIVEN** a Bijstandsaanvraag whose asset and income tests are answered in `sensitiveProperties`
- **AND** a mover who cannot read them
- **WHEN** the mover takes "Toetsing afronden"
- **THEN** the guard SHALL pass, and nothing of the tests SHALL be in its answer
