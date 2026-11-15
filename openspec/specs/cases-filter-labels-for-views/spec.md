# cases-filter-labels-for-views Specification

## Purpose
What dossiq does for cases filter labels for views. The requirements were first written by the change `cases-filter-labels-for-views` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-cases-filter-labels-for-views/`) and cover: The cases list names waiting and handler as the board.

## Requirements

### Requirement: The cases list names waiting and handler as the board

The Cases page SHALL name the `waitingOnApplicant` filter "Status: wacht op inwoner" and the `assignee` filter
"Behandelaar" for a Dutch reader, on the Active line, under a saved view and in the edit dialog's criteria.

#### Scenario: Wacht op inwoner
- GIVEN the saved view "Wacht op inwoner" (waitingOnApplicant true)
- THEN its criteria read "Status wacht op inwoner"
