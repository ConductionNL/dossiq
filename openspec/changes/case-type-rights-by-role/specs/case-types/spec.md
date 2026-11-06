# case-types Delta

## ADDED Requirements

### Requirement: The Overview shows a case type's rights per role

The case type Overview SHALL show the rights matrix as one row per role with what the role may see, whether it
handles and decides, and what it may do with documents. The full matrix SHALL stay on the Rights tab.

#### Scenario: A permit case type

- **GIVEN** a matrix granting Vergunningverlener read, update and delete in Vergunningen and list in Toezicht
- **THEN** its row reads Vergunningen, Toezicht · Ja · Nee · Alles
- **AND** Bewerken opens the Rights tab with the department x confidentiality matrix
