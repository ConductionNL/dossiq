## ADDED Requirements

### Requirement: The custody chain reads as rows with the current holder marked

The Archiving tab SHALL draw each holding as a row with the unit's initials, the unit, its period (both ends for a closed holding) and a pill saying whether the unit holds the case now or handed it over.

#### Scenario: a case handed from the KCC to the Woo team
@e2e exclude covered by tests/vitest/caseCustodyPanel.spec.js; the live chain needs example holdings

- **GIVEN** a case held by the KCC from 3 okt 14:10 to 14:18 and since then by Team Woo en informatie
- **WHEN** the handler opens the Archiving tab
- **THEN** the KCC row SHALL read "Van 3 okt 14:10 tot 3 okt 14:18" with the pill "Overgedragen"
- **AND** the Woo row SHALL read "In handen sinds 3 okt" with the pill "Houder nu"
- **AND** the "Overgedragen" pill and the initials SHALL use the soft ink, and a request's "Overdragen" SHALL be a secondary button next to "Houden, met reden"
