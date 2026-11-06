# party-pages Specification

## Purpose
What dossiq does for party pages. The requirements were first written by the change `contacts-bsn-plain-head` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-contacts-bsn-plain-head/`) and cover: The contacts list neither sorts nor filters on the BSN; A party's list cards rule above their first row; A finished case's pill is green; A case pill takes its status type's colour; and 4 more. The contact and organisation states the pipelinq client boards gained (account owner, note templates, deletion and AVG requests, an ended relationship, BRP failures, mail from an unknown address, the relationship filter) follow decision 305.

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

### Requirement: A contact names the colleague who owns it

A contact page SHALL show the contact's account owner, the colleague who manages the contact, with the contact's other facts. It mirrors pipelinq `unify-client-contact` REQ-PUCC-008 (decision 305). Ownership SHALL NOT restrict who may read or edit the contact.

#### Scenario: DqContact
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **GIVEN** Sanne de Vries, managed by Pieter Jansen
- **THEN** her facts read "Accounteigenaar" with "Pieter Jansen, de collega die dit contact beheert"

### Requirement: A contact's notes start from a template

A contact page SHALL carry a Notities card with "Notitie toevoegen" and a Sjablonen list the administrator keeps under Notitiesjablonen. It mirrors pipelinq `entity-notes` Note Templates (decision 305).

#### Scenario: DqContact with templates
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **GIVEN** the templates Eerste contact, Terugbelgesprek, Klachtafhandeling and Afspraak gemaakt
- **WHEN** a handler opens Sjablonen on the Notities card
- **THEN** the four templates are listed

#### Scenario: DqContact without notes
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **GIVEN** a contact without notes
- **THEN** the card reads "Nog geen notities bij dit contact."

### Requirement: Deleting a contact and the AVG requests sit under Meer

A contact page SHALL offer, under Meer, "Contact verwijderen", "Gegevens exporteren (inzage)", "Gegevens wissen (verwijderverzoek)" and "Relatie beëindigen". Deleting SHALL always ask for confirmation, and the AVG actions SHALL show only to whoever holds the right. It mirrors pipelinq `client-management` Client Deletion and GDPR Data Subject Rights (decision 305).

#### Scenario: DqContact deletion names what hangs on the contact
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **GIVEN** Sanne de Vries with 8 cases and 4 tickets
- **WHEN** a handler chooses Contact verwijderen
- **THEN** the confirmation reads "Sanne de Vries hangt aan 8 zaken en 4 tickets" and says the cases and tickets keep their data but no longer point to this contact

#### Scenario: DqContact access export
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **WHEN** a handler with the right chooses Gegevens exporteren (inzage)
- **THEN** one file is offered with the data, the cases, the tickets, the contact moments and the notes, also as CSV

#### Scenario: DqContact erasure preview
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **WHEN** a handler with the right chooses Gegevens wissen (verwijderverzoek)
- **THEN** the preview lists what is erased and that the cases and tickets stay with the name "Geanonimiseerd"
- **AND** it says erasure cannot be undone and is logged with a reference such as AVG-2026-0142

### Requirement: An ended relationship names its destruction date

A contact whose relationship has ended SHALL show the end date, who ended it, and the destruction date two years later. An active contact SHALL show no destruction date. It mirrors pipelinq `client-retention` REQ-PCR-001 (decision 305).

#### Scenario: DqContact after the relationship ended
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **GIVEN** the relationship was ended on 12 September 2026 by Pieter Jansen
- **THEN** the contact reads "Relatie beëindigd" with that date and name, and "Vernietiging 12 september 2028, twee jaar na het einde"

### Requirement: A failed BRP lookup says so and is logged

A contact's identity block SHALL show a BRP lookup that fails as one of two messages, keep the data editable without verification, and log the attempt with its correlation id and outcome. It mirrors pipelinq `bsn-validatie-en-brp-lookup` REQ-BSN-003 and REQ-BSN-005 (decision 305).

#### Scenario: DqContact when the BRP is unreachable
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **GIVEN** the BRP does not answer
- **THEN** the identity block reads "BRP momenteel niet bereikbaar, probeer over enkele minuten opnieuw."
- **AND** the attempt is in the audit log with its correlation id and the outcome fout (503)

#### Scenario: DqContact when the BSN is not found
@e2e exclude not built yet; the board follows pipelinq PqKlant (design-system #1127)

- **GIVEN** the BRP knows no person with the BSN
- **THEN** the identity block reads "BSN niet aangetroffen in BRP, controleer invoer."

### Requirement: Mail from an unknown address offers a contact person

An organisation page SHALL offer to create a contact person when mail arrives from an address that belongs to the organisation but is no contact person yet. It mirrors pipelinq `email-calendar-sync` Email-to-contact matching rule (decision 305).

#### Scenario: DqOrganisatie
@e2e exclude not built yet; the board follows pipelinq PqOrganisatie (design-system #1127)

- **GIVEN** mail today at 09:14 from info@buurtoost.nl, an address of Buurtvereniging Oost without a contact person
- **THEN** the organisation page asks "Contactpersoon aanmaken voor info@buurtoost.nl?" with Contactpersoon aanmaken and Negeren

### Requirement: The contacts list filters on relationship

The contacts list SHALL offer a filter on the contact's relationships. Only active relationships SHALL count, and "Zonder relaties" SHALL show the contacts that hang on no one. It mirrors pipelinq `contact-relationship-mapping` Relationship search and filtering (decision 305).

#### Scenario: DqContacten
@e2e exclude not built yet; the board follows pipelinq PqContacten (design-system #1127)

- **WHEN** a handler opens Filteren op relatie
- **THEN** the choices include "Werknemer van Buurtvereniging Oost", "Heeft relatie werkgever" and "Zonder relaties (23)"
- **AND** a contact whose work relationship has ended is left out
