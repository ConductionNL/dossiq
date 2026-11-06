# case-access-purpose Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: Opening a sensitive case records its purpose

When a reader opens a case whose case type lists special categories of personal data, the case page SHALL ask the
reader's purpose before it shows the case, and dossiq SHALL write the purpose to the case's audit trail with the reader,
the time, the purpose, the reader's own words for "other" and the categories. dossiq SHALL refuse a case the reader
cannot read, a purpose that is not offered, an "other" without words, and a purpose it could not write; a refused
purpose SHALL keep the case closed. A case whose case type lists no categories SHALL open without a purpose.

#### Scenario: A Melding Jeugdwet case

- **GIVEN** the Melding Jeugdwet case type lists health
- **WHEN** Pieter opens a Melding Jeugdwet case and picks "Handling the case"
- **THEN** an audit trail entry `dossiq.case.access-purpose` with reader pieter, the time, purpose handling and categories health is on the case, and the case opens

#### Scenario: The purpose cannot be written

- **GIVEN** OpenRegister refuses the audit write
- **THEN** the endpoint answers 503 and the case stays closed
