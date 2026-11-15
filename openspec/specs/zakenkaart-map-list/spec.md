# zakenkaart-map-list Specification

## Purpose
What dossiq does for zakenkaart map list. The requirements were first written by the change `zakenkaart-map-list` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-zakenkaart-map-list/`) and cover: A map list row reads address, case number and names its type and status; The empty locations line sits on the card's inset.

## Requirements

### Requirement: A map list row reads address, case number and names its type and status

In the simple profile, each row of the case map's numbered list SHALL show the case's address and number under its
title ("Lindelaan 40 · 2026-0082"), the number alone when the case has no location, and pills naming the case type and
the status by their display names, the status pill in its status type's colour. The address SHALL be the label of the case's first `case-location`, looked up once
per case per page load.

#### Scenario: a located case with a case location

- **GIVEN** the case index in map view and a located Woo-verzoek case "In behandeling" with a case location "Lindelaan 40"
- **THEN** its row reads "Lindelaan 40 · 2026-0082" with the pills "Woo-verzoek" and "In behandeling"

#### Scenario: a located case without a case location

- **GIVEN** a located case with no `case-location`
- **THEN** its row reads the case number alone, with the same pills

### Requirement: The empty locations line sits on the card's inset

The case page's "Locaties" card SHALL show its empty line ("Nog geen locatie") at 14px in the muted text colour with no
padding of its own, so it starts the card's body gap below the title.

#### Scenario: DqZaakLocatie "Nog geen locatie"

- **GIVEN** a case without locations
- **THEN** "Nog geen locatie" starts 12px below the "Locaties" title
