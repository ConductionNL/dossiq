# my-work-landing Delta: my-teams-queue

## ADDED Requirements

### Requirement: My team shows the unclaimed work of the reader's own teams

The My team view of the landing page SHALL carry the Dashboard's
`your-teams-queue` preset unchanged, so its queue SHALL be the Queue page's
filter narrowed to `assignedGroup: @myGroups`.

#### Scenario: The team view and the Dashboard agree

- **GIVEN** the manifest
- **THEN** the first widget of the My team view SHALL equal the `your-teams-queue` preset
- **AND** its filter SHALL equal the Queue page's filter plus `assignedGroup: "@myGroups"`

@e2e exclude A manifest invariant, asserted in tests/vitest/landingViews.spec.js.
