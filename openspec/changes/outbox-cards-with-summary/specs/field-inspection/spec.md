## ADDED Requirements

### Requirement: The outbox says what each waiting change holds

The field inspection outbox SHALL draw each waiting change as a card with its label, a line saying what it holds (its summary, else its last error, else the operation and time) and its state.

#### Scenario: Pieter's outbox
@e2e exclude config and data pinned by ZuiddrechtOutboxTest and fieldInspectionBoard.spec.js

- **GIVEN** the Zuiddrecht outbox
- **WHEN** Pieter opens Inspectie in het veld
- **THEN** the outbox SHALL read "Molenweg 4, checklist" with "18 antwoorden, 4 foto's" and "Wacht", then "Kerkplein 7, foto" with "Vraag 7", then the conflict with its reason
