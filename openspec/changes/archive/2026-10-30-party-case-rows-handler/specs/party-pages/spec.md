# party-pages Delta

## ADDED Requirements

### Requirement: A party case row names its handler

The case rows on the contact and organisation pages SHALL end their meta line with the case's handler by display name, and drop that part when the case has no handler.

#### Scenario: DqContact Zaken card

- **THEN** a row reads "2026-0110 · Woo-verzoek · behandelaar Pieter Jansen"

#### Scenario: DqOrganisatie open case side card

- **THEN** the row reads "2026-4833 · behandelaar Mehmet Yıldız"
