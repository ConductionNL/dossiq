## ADDED Requirements

### Requirement: Case-type views sit under More default views

The case-type views /api/manifest adds to the Cases page SHALL be secondary default views, each with a description, so
the panel lists them under "Meer standaardweergaven" and never shows a case type id as their line.

#### Scenario: Complaints and a pinned case type
- GIVEN an instance with case type klacht-behandeling and a user who pinned Bezwaar
- THEN both views are secondary, described "Case type complaint handling" and "Bezwaar"
