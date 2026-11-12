# case-page

## ADDED Requirements

### Requirement: The case page shows its task panel beside the tabs (REQ-CRS-17)

The case page SHALL show the case's tasks in a panel beside the tabs, not inside one of them. The panel SHALL stay in view while the reader switches tabs.

Rows: `3.18` (dossiq matrix).

#### Scenario: The panel stays while tabs change
- **GIVEN** a case with two open tasks
- **WHEN** you open the Documents tab and then the History tab
- **THEN** the task panel beside the tabs lists both tasks the whole time
