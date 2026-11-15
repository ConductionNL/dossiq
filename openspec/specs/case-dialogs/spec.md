# case-dialogs Specification

## Purpose
A case dialog says what it does: its title names the act, and its fields, lists and limits read as the drawn boards show them.

## Requirements

### Requirement: A case dialog is titled by what it does

A case dialog that opens a form SHALL be titled by its act, SHALL confirm with that act's verb, and SHALL ask its fields
in the order the board draws them, without the schema's data-model hints.

#### Scenario: DqZaakDialogen

- **WHEN** the handler chooses Partij toevoegen or Object koppelen
- **THEN** the dialog is titled "Partij toevoegen" or "Object koppelen" and confirms with "Toevoegen" or "Koppelen"

### Requirement: Contact vastleggen asks channel and direction as two selects

The Log contact dialog SHALL ask the channel and the direction as two selects in one row, each labelled as optional and
each holding a value from the start. Every channel and direction the dialog offered SHALL stay choosable, and the saved
values SHALL not change.

#### Scenario: DqZaakDialogen

- **WHEN** the handler opens Contact vastleggen
- **THEN** Kanaal (niet verplicht) and Richting (niet verplicht) are selects on one row, set to Telefoon and Inkomend
- **AND** choosing E-mail and Uitgaand saves `email` and `outbound` as before

### Requirement: Flow starten names its list and its limit

The Start a flow dialog SHALL list the case type's startable flows under the legend "Sub-process", SHALL say beneath
them that only flows the case type allows are listed, and SHALL confirm with "Start it".

#### Scenario: DqZaakDialogen

- **WHEN** the handler opens Flow starten on a case whose type lists flows
- **THEN** the flows are under "Deelproces" with their descriptions, "Alleen flows die het zaaktype toestaat." is beneath
  them, and the confirm reads "Starten"

### Requirement: Ruwe gegevens copies the stored case and says who it is for

The Raw data dialog SHALL state once that it shows the case as OpenRegister stores it, SHALL say that it is for
administrators and that opening it is recorded in the processing log, and SHALL offer Cancel and Copy, where Copy puts
the shown JSON on the clipboard and confirms it.

#### Scenario: DqZaakDialogen

- **WHEN** an administrator opens Ruwe gegevens on a case
- **THEN** the subtitle appears once, the line "Alleen voor beheerders. Lezen kost niets, maar het openen komt in het
  verwerkingenlog." is under the JSON, and the buttons read Annuleren and Kopiëren
- **AND** Kopiëren puts the JSON on the clipboard and says "Gekopieerd naar uw klembord."

### Requirement: Zaak bewerken says what it does not change and asks the handler first

The case edit form SHALL carry the subtitle that status and term change through Next step, SHALL ask the handler right
after the case type, and SHALL label the requester as the applicant (Verzoeker).

#### Scenario: DqZaakDialogen

- **WHEN** the handler opens Bewerken on a case
- **THEN** the form reads "De velden van de zaak. Status en termijn wijzigt u via Volgende stap." under its title
- **AND** asks Zaaktype, Behandelaar, Titel, Omschrijving, Vertrouwelijkheid, Intakekanaal, Startdatum, Geplande
  einddatum, Impact, Urgentie and Verzoeker in that order
