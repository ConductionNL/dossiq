# features-overlay-category Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [features-overlay-category](../../)

## ADDED Requirements

### Requirement: The overlay carries each feature's category

Every entry of `openspec/features.overlay.json` SHALL carry the `category` the dashboard groups it under, equal to the
one in `docs/features.json`, so regenerating the file keeps it.

#### Scenario: A spec commit

- **GIVEN** a commit that touches `openspec/specs/`
- **WHEN** the pre-commit hook regenerates `docs/features.json`
- **THEN** every feature keeps its `category`
