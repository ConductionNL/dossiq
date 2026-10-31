## ADDED Requirements

### Requirement: The history chips are declared timeline kinds

dossiq SHALL declare the timeline kinds `statuswijziging` (Status), `document` (Documenten), `bericht` (Berichten), `taak` (Taken) and `toewijzing` (Toewijzing), each with the one field that says what happened, and none with a follow-up.

#### Scenario: case history
- **GIVEN** entries of those kinds on a case
- **THEN** the history shows the chips Status, Documenten, Berichten, Taken and Toewijzing with their counts
