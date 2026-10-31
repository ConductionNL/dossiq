# enforcement-advice-page Specification

## Purpose
The enforcement advice page carries the layout board dossiq/DqHandhaving draws.

## Requirements

### Requirement: The enforcement advice page follows board DqHandhaving

The `LhsRecommendationDetail` page SHALL set `follow: false`, SHALL show the recommendation and the deviation as side
cards after the Case and Violation class cards, SHALL keep every field the former data panels showed, and SHALL title
its history card with a string that reads "Historie" in Dutch.

#### Scenario: Advice header

- **GIVEN** an enforcement advice page
- **THEN** the header shows "Afwijken met reden" and "Meer" without a Follow toggle or follower count

#### Scenario: Side column

- **GIVEN** an enforcement advice page
- **THEN** the side column shows Zaak, Classificatie, Aanbeveling, Afwijking en definitief and Historie, in that order
- **AND** the main column shows the matrix and the violation only

#### Scenario: Wat nu? button

- **GIVEN** an enforcement advice page whose advice settles on a warning or a pre-announcement
- **THEN** the Wat nu? card's button reads "Waarschuwing opstellen" or "Vooraankondiging opstellen", as text without an icon
