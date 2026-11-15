# verdict-row-cites-its-ground Specification

## Purpose
The dossier listing carries the review verdict of each document together with the grounds it cites.

## Requirements

### Requirement: A document's verdict cites its grounds on the row

The case files' Assessment column SHALL draw, under a verdict that cites grounds, the line "grond" followed by the
codes of the grounds its assessment cites, read from the dossier listing's `reviewGrounds`. A verdict that cites no
ground SHALL draw its pill alone.

#### Scenario: DqZaakDocumenten "Mail aannemer over planning.eml"
- GIVEN a document assessed as partly public citing ground 5.1.2.e
- WHEN the Bestanden tab lists it
- THEN its Assessment cell shows "Deels openbaar" with "grond 5.1.2.e" under it
