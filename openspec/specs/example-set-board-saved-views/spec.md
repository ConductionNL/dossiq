# example-set-board-saved-views Specification

## Purpose
The Zuiddrecht example set holds the saved views the DqFilter boards draw in Pieter Jansen's Weergaven tab.

## Requirements

### Requirement: Pieter owns the boards' three views

The set SHALL give Pieter Jansen the private case-list views "Termijn deze week", "Wacht op inwoner" (described as
"Status wacht op inwoner, gedeeld met team Woo") and "Opgeschort" (described as "Status opgeschort, alle teams", with
the conditions of the case list's Suspended lens).

#### Scenario: DqFilterBeheren

- **GIVEN** the Zuiddrecht set is installed
- **WHEN** Pieter opens the Weergaven tab of Alle zaken
- **THEN** "Mijn weergaven" lists Termijn deze week, Wacht op inwoner and Opgeschort

### Requirement: A colleague shares a view with Pieter

The set SHALL give Anouk Bakker the case-list view "Woo-verzoeken team", shared to use with the group Woo en
informatie, with the conditions of the default view "Woo requests".

#### Scenario: Gedeeld met mij

- **GIVEN** the Zuiddrecht set is installed and Pieter is in Woo en informatie
- **WHEN** Pieter opens the Weergaven tab
- **THEN** "Gedeeld met mij" lists "Woo-verzoeken team" by Anouk Bakker
