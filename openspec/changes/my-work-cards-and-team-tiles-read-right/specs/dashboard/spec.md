# dashboard delta: my-work-cards-and-team-tiles-read-right

## ADDED Requirements

### Requirement: The Recently opened tile shows when, whatever its width (REQ-DASH-030)

The Recently opened tile SHALL keep its date column visible inside the tile. When
the tile is narrower than its columns, the case title SHALL give way and end in an
ellipsis, and the number and the date SHALL keep their width.

#### Scenario: The date stays visible in a narrow tile
@e2e exclude {a layout measurement; the column setup is asserted in tests/vitest/recentTileViewedAt.spec.js and measured live at 1440px}

- **GIVEN** the Dashboard at 1440px wide, where the tile is about 340px
- **WHEN** the reader has opened a case with a long title
- **THEN** the date column SHALL be inside the tile
- **AND** the title SHALL end in an ellipsis
