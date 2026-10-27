# at-risk-reads-the-running-term Delta

## ADDED Requirements

### Requirement: At risk reads the case's running term

The processing-time at-risk rows SHALL measure an open case against the term from its start to its `deadline` when it
has one, and against its case type's `targetDays` otherwise.

#### Scenario: A Woo request four days from its deadline

- **GIVEN** a Woo request 24 days into a 28-day term whose type's target is 56 days, listed at threshold 0.5
- **THEN** it is listed, 4 days left, critical
