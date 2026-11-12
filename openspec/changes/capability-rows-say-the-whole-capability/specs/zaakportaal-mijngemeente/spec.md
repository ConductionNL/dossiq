# zaakportaal-mijngemeente

## ADDED Requirements

### Requirement: A resident from another EU country logs in with eIDAS (REQ-CRS-40)

The portal SHALL offer login with eIDAS next to DigiD and eHerkenning when a broker offers it. A login below the substantial assurance level SHALL be refused.

Rows: `12.8` (dossiq matrix).

#### Scenario: A low assurance login
- **GIVEN** a resident logging in with an eIDAS means at assurance level low
- **WHEN** the broker returns the login
- **THEN** the portal refuses it and says a higher level is needed
