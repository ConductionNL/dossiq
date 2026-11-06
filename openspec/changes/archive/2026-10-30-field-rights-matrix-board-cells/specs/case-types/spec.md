# case-types Delta

## ADDED Requirements

### Requirement: The field rights matrix draws its rows as the board does

The field rights matrix SHALL set its heads and cells at the normal line height, vertically centred, pad its body cells
11px 12px, and draw the field name in weight 400 on the card's own ground.

#### Scenario: DqZaaktypeVelden

- **GIVEN** the matrix with five columns whose heads wrap to five lines
- **WHEN** the Fields tab is drawn
- **THEN** the head row is about 105px high and "Bouwkosten" reads in the regular weight on white
