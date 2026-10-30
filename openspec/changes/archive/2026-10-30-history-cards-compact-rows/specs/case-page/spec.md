# case-page Delta

## ADDED Requirements

### Requirement: The social and enforcement Historie cards draw compact rows

The side card Historie of a social-domain case and of an enforcement advice SHALL draw its stacked rows compact, 10px
above and below, the first row 10px under the title, as DqSociaalDomein and DqHandhaving draw them.

#### Scenario: DqSociaalDomein Historie

- **GIVEN** a Melding Jeugdwet case in the board look
- **WHEN** its Historie card lists "Inzage medisch verslag" with "Fatma Kaya · doel onderzoek · 6 okt"
- **THEN** the action starts 21px under the card title and the row is padded 10px above and below

#### Scenario: DqHandhaving Historie

- **GIVEN** an enforcement advice in the board look
- **WHEN** its Historie card lists "Advies berekend" with "Systeem · 24 okt 13:37"
- **THEN** the row is padded 10px above and below
