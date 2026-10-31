# case-types Delta

## ADDED Requirements

### Requirement: A case type marks its side statuses

A status type SHALL be able to say it is off the main path (`offPath`), and the case's stages strip SHALL leave such a status out unless the case is in it.

#### Scenario: Melding Jeugdwet

- **THEN** the strip draws Melding ontvangen to Afgehandeld, without Verlenging aangevraagd and Ingetrokken
