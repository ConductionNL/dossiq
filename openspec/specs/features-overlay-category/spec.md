# features-overlay-category Specification

## Purpose
What dossiq does for features overlay category. The requirements were first written by the change `features-overlay-category` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-features-overlay-category/`) and cover: The overlay carries each feature's category.

## Requirements

### Requirement: The overlay carries each feature's category

Every entry of `openspec/features.overlay.json` SHALL carry the `category` the dashboard groups it under, equal to the
one in `docs/features.json`, so regenerating the file keeps it.

#### Scenario: A spec commit

- **GIVEN** a commit that touches `openspec/specs/`
- **WHEN** the pre-commit hook regenerates `docs/features.json`
- **THEN** every feature keeps its `category`
