## ADDED Requirements

### Requirement: The calculated deadline is the fallback for a case without a term

The case `deadline` calculation SHALL be declared a fallback, so a deadline its statutory term keeps is not reported as a calculation that failed to materialise.

#### Scenario: rematerialising a case with a term
- **GIVEN** a case whose statutory term ends 29 okt while startDate plus the term gives 29 jun
- **WHEN** the case calculations are rematerialised
- **THEN** the case keeps 29 okt and counts as touched
