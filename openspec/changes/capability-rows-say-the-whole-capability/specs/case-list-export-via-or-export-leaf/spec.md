# case-list-export-via-or-export-leaf

## ADDED Requirements

### Requirement: The exports area shows how often each export was downloaded (REQ-CRS-37)

Each export in the exports area SHALL show how many times it was downloaded. A download SHALL raise that count by one.

Rows: `10.7` (dossiq matrix).

#### Scenario: A second download
- **GIVEN** an export downloaded once
- **WHEN** a user downloads it again
- **THEN** the exports area shows 2 downloads
