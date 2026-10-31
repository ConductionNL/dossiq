# case-type-views-under-more Specification

## Purpose
What dossiq does for case type views under more. The requirements were first written by the change `case-type-views-under-more` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-case-type-views-under-more/`) and cover: Case-type views sit under More default views.

## Requirements

### Requirement: Case-type views sit under More default views

The case-type views /api/manifest adds to the Cases page SHALL be secondary default views, each with a description, so
the panel lists them under "Meer standaardweergaven" and never shows a case type id as their line.

#### Scenario: Complaints and a pinned case type
- GIVEN an instance with case type klacht-behandeling and a user who pinned Bezwaar
- THEN both views are secondary, described "Case type complaint handling" and "Bezwaar"
