# my-work

## ADDED Requirements

### Requirement: An overdue case is marked on its page and in the case list (REQ-CRS-34)

A case past its deadline SHALL be marked overdue on its case page and on its row in the case list. A case due today SHALL NOT be marked overdue.

Rows: `8.2` (dossiq matrix).

#### Scenario: One late, one due today
- **GIVEN** a case one day past its deadline and a case due today
- **WHEN** you open the case list
- **THEN** only the first row is marked overdue
