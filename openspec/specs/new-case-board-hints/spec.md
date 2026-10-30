# new-case-board-hints Specification

## Purpose
The New case form speaks to the person filling it in.

## Requirements

### Requirement: The New case form carries the board's helper lines

The Cases page New case form SHALL confirm with "Create case" ("Zaak aanmaken") and SHALL show under Case type
"Determines the statuses, the term and the documents", under Intake channel "How the request came in", under Planned
end date "From the case type's term", under Impact "How much it matters if it goes wrong" and under Urgency "How fast
the case matters", and no helper line under Title, Requester, Description, Assigned to and Confidentiality.

#### Scenario: DqNieuweZaak

- **GIVEN** the Cases page in the simple structure
- **WHEN** the handler opens New case
- **THEN** the helper lines and the confirm button read as the board draws them
