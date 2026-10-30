# phase-days-from-completed-visits Delta

## ADDED Requirements

### Requirement: Days per phase counts completed visits

The process-mining days-per-phase matrix SHALL be computed from dwell visits that ended (by a next status or the case's
close), not from the visit an open case is still in.

#### Scenario: An open case in its second status

- **GIVEN** an open case that was in Ontvangst for two days and is now in Beoordeling
- **THEN** the matrix has an Ontvangst column for it and no Beoordeling column
