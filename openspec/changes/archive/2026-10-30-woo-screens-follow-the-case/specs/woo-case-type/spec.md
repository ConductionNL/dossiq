# woo-case-type delta: woo-screens-follow-the-case

## ADDED Requirements

### Requirement: The Woo case screens follow the case they show (REQ-WCT-SCR-01)

The Woo request's screens MUST say where the case is in words and dates the reader reads. The delivered-sets list MUST show its dates in the reader's locale and its states as words. A status after the decision MUST NOT carry the role of the handling steps, so the what-now card does not send the case back to handling. The Dutch catalogue MUST mark an optional field with one wording, the library's "(niet verplicht)".

#### Scenario: The delivered sets read as dates and words
@e2e exclude {asserted in tests/vitest/manifestWooDeliveredSets.spec.js on the shipped manifest and catalogue}

- **GIVEN** a published Woo request with one delivered set
- **WHEN** a Dutch reader opens the Aangeleverde sets section
- **THEN** the delivery date MUST be a date in their locale, not an ISO timestamp
- **AND** the state MUST read "Aangeleverd en vastgelegd", not `frozen`

#### Scenario: At Publicatie the card does not ask for handling
@e2e exclude {asserted in tests/Unit/Settings/WooStatusRolesTest.php on the shipped fragment}

- **GIVEN** a Woo request whose decision is recorded, at the status Publicatie
- **WHEN** the handler opens the case
- **THEN** the what-now card MUST NOT read "Wat nu? Behandelen" or "Daarna: het besluit"
