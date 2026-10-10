# my-work delta: my-work-cards-and-team-tiles-read-right

## ADDED Requirements

### Requirement: A My Work card keeps its content inside the card and its late deadline readable (REQ-MW-CARD-01)

A card on Assigned to me SHALL keep every chip inside the card; a chip whose text
does not fit SHALL end in an ellipsis and carry its full text as a tooltip. A
deadline that is late SHALL be drawn in a colour with at least 4.5:1 contrast on
the card's background.

#### Scenario: A long case type stays inside the card
@e2e exclude {a layout measurement on a themed instance; the chip rules and tooltip are asserted in tests/vitest/myWorkCaseCard.spec.js and measured live on the review instance}

- **GIVEN** a case whose case type is "Omgevingsvergunning kleine bouwactiviteit"
- **WHEN** the handler opens Assigned to me in the cards view
- **THEN** the case type chip SHALL end inside the card
- **AND** its tooltip SHALL show the full case type

#### Scenario: A late deadline can be read
@e2e exclude {contrast depends on the active theme's tokens; the text token is asserted in tests/vitest/myWorkCaseCard.spec.js and the contrast measured live}

- **GIVEN** a case whose deadline has passed
- **WHEN** the handler opens Assigned to me in the cards view
- **THEN** the deadline text SHALL have at least 4.5:1 contrast on the card

### Requirement: Your team's queue shows its deadlines as dates (REQ-MW-CARD-02)

The Deadline column of Your team's queue and of Cases you follow SHALL show the
deadline as a date in the reader's locale, not as the stored ISO string.

#### Scenario: The team queue shows a date
@e2e exclude {the column carries the library's own date format; asserted in tests/vitest/landingViews.spec.js and checked live}

- **GIVEN** an unassigned case of the reader's team with deadline 2026-12-07
- **WHEN** the reader opens My work, My team
- **THEN** the Deadline column SHALL show a date such as "7 dec. 2026"
