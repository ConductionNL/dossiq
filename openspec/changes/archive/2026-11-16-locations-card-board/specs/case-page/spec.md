## ADDED Requirements

### Requirement: The Locaties card is a short map with zoom and fit

The case's Locaties card SHALL draw its map 120px high with zoom and fit-all
buttons and without locate or fullscreen buttons.

#### Scenario: The card's map
@e2e exclude The map's controls are asserted in the change's vitest (task 1.2); the example location of task 2.1 is example data, checked by ZuiddrechtLindelaanLocationTest.

- **GIVEN** a case with a location
- **WHEN** the Locaties card renders
- **THEN** its map SHALL be 120px high with zoom and fit-all buttons
- **AND** it SHALL show no locate and no fullscreen button
