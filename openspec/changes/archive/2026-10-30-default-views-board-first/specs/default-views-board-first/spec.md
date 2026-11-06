# default-views-board-first Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: The case list's default views are the board's three first

The Cases page SHALL declare Mijn zaken, Alle lopende zaken and Termijn verstreken as its first default views, each
with the board's line, and SHALL keep every other default view, marked `secondary`.

#### Scenario: DqFilterBeheren

- **WHEN** Pieter opens the Weergaven tab of Alle zaken
- **THEN** Standaardweergaven lists the three with their lines, and the others sit under "Meer standaardweergaven"
