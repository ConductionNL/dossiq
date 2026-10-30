# case-lists Specification

## Purpose
What dossiq does for case lists. The requirements were first written by the change `saved-views-default-views` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-saved-views-default-views/`) and cover: The case lists ship default views.

## Requirements

### Requirement: The case lists ship default views

The Cases page SHALL declare the default views "Mine, overdue", "Mine, due
this week" and "Unclaimed, overdue", each the literal union of the quick-filter
chips it combines. The Queue page SHALL declare "Late" and "This week", each the
deadline window of the matching chip. Every name SHALL have a Dutch translation.

#### Scenario: a default is two chips combined

- **GIVEN** the Cases manifest
- **WHEN** the `mine-overdue` default is read
- **THEN** its filters equal the Mine chip's filter merged with the Overdue chip's filter
- @e2e exclude {manifest declaration, asserted in tests/vitest/defaultSavedViews.spec.js}

#### Scenario: Dutch names

- **GIVEN** the Dutch locale
- **WHEN** the saved-views menu opens on Cases
- **THEN** it lists "Mijn zaken, te laat", "Mijn zaken, deze week" and "Niet toegewezen, te laat" under Standaardweergaven
- @e2e exclude {rendering is nextcloud-vue's, covered there; names asserted in tests/vitest/defaultSavedViews.spec.js}
