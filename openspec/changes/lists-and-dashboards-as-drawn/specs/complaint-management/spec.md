# complaint-management Delta: lists-and-dashboards-as-drawn

## ADDED Requirements

### Requirement: The complaint patterns sit on the process analysis page

The frequency analysis SHALL be drawn at the foot of the process analysis page, as board DqProcesanalyse draws "Klachten: terugkerende patronen": the last full quarter against the one before, four figures against their targets, the system report with its pdf export, complaints per category (a rise above 50 % flagged), per department, per channel and per month, and the average handling time per category. It SHALL be drawn only for the roles of that page. It SHALL NOT carry the per-employee threshold alert, which goes only to the hr coordinator and the department head.

#### Scenario: A manager reads the quarter's complaint patterns
@e2e exclude needs the SIG endpoint GET /api/complaints/patterns; the configuration is covered by tests/vitest/processComplaintPatterns.spec.js
- **GIVEN** complaints in the last two quarters
- **WHEN** a manager opens the process analysis page
- **THEN** the complaint cards show the quarter's figures, the system report and the breakdowns
- **AND** no card names an employee
