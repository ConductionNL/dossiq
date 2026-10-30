# party-pages Specification

## Purpose
What dossiq does for party pages. The requirements were first written by the change `contacts-bsn-plain-head` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-contacts-bsn-plain-head/`) and cover: The contacts list neither sorts nor filters on the BSN; A party's list cards rule above their first row; A finished case's pill is green; A case pill takes its status type's colour; and 4 more.

## Requirements

### Requirement: The contacts list neither sorts nor filters on the BSN

The contacts list SHALL NOT offer sorting or a header filter on the BSN column.

#### Scenario: DqContacten

- **THEN** the BSN head is a plain label

### Requirement: A party's list cards rule above their first row

The Zaken, Tickets and Laatste contactmomenten cards of a contact or organisation page SHALL draw a hairline above
their first row.

#### Scenario: DqContact

- **THEN** a line runs under "Zaken" above the first case

### Requirement: A finished case's pill is green

The contact and organisation pages' Zaken cards SHALL colour a finished case's status pill green and an open case's
purple.

#### Scenario: DqContact

- **GIVEN** Sanne's finished case "Kapotte lantaarnpaal Lindelaan"
- **THEN** its "Afgehandeld" pill is green

### Requirement: A case pill takes its status type's colour

The contact and organisation pages' case pills SHALL take the colour of the case's status type.

#### Scenario: DqOrganisatie

- **THEN** "Besluitvorming" is teal and "Afgehandeld" green

### Requirement: The party lists show eight rows per page

The contacts and organisations lists SHALL show eight rows per page.

#### Scenario: DqOrganisaties

- **GIVEN** 23 organisations
- **THEN** the first page shows 8 and the pager follows the eighth row

### Requirement: Reachable via is one paragraph

The contact page's "Reachable via" card SHALL write the last contact and the registration note as one muted paragraph. Without a contact moment, it SHALL still show the note.

#### Scenario: Sanne

- **THEN** it reads "Laatst contact op 14 oktober, via e-mail. Telefoon en e-mail staan niet in de basisregistratie."

### Requirement: A party case row names its handler

The case rows on the contact and organisation pages SHALL end their meta line with the case's handler by display name, and drop that part when the case has no handler.

#### Scenario: DqContact Zaken card

- **THEN** a row reads "2026-0110 · Woo-verzoek · behandelaar Pieter Jansen"

#### Scenario: DqOrganisatie open case side card

- **THEN** the row reads "2026-4833 · behandelaar Mehmet Yıldız"

### Requirement: A party's address is two lines of one block

The contact and organisation side cards SHALL show the address as two lines of one block, and the map link SHALL carry a map pin.

#### Scenario: DqOrganisatie KvK en adres

- **THEN** "Oosthavenweg 12" and "2999 AJ Zuiddrecht" are 22px apart, and "Zaken op de kaart" follows a map pin
