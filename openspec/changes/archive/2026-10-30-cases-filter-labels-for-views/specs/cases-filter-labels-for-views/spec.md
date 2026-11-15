## ADDED Requirements

### Requirement: The cases list names waiting and handler as the board

The Cases page SHALL name the `waitingOnApplicant` filter "Status: wacht op inwoner" and the `assignee` filter
"Behandelaar" for a Dutch reader, on the Active line, under a saved view and in the edit dialog's criteria.

#### Scenario: Wacht op inwoner
- GIVEN the saved view "Wacht op inwoner" (waitingOnApplicant true)
- THEN its criteria read "Status wacht op inwoner"
