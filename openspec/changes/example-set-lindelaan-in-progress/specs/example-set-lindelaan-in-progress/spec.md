# example-set-lindelaan-in-progress Delta

## ADDED Requirements

### Requirement: The example Woo case is in progress with its contact moments

The Zuiddrecht example set SHALL seed the Woo case "Verlichting fietspad Lindelaan" in a Woo status whose role is
in-progress, without `wooPublicationStatus`, and SHALL seed four contact moments on it, after it: an inbound telephone
call, an outbound and an inbound message through the portal (`webformulier`), and an inbound e-mail. Every channel,
direction and nature SHALL be a value the contact moment schema allows.

#### Scenario: the installed set

- **GIVEN** the Zuiddrecht example set installed
- **THEN** the case "Verlichting fietspad Lindelaan" shows the "Wat nu?" card of its in-progress stage
- **AND** its Contact tab counts 4 contact moments

### Requirement: The example Woo case holds seven documents, four assessed, and no decision yet

The case SHALL hold seven documents as `informatieobject` rows linked by `zaakinformatieobject` (the way a case's
documents are found), four of them assessed (two public, one in part, one not), as DqZaakDocumenten draws "4 van 7
beoordeeld". The set SHALL seed no recorded decision and no notice of default on it: DqZaakBesluiten draws the decision
as a concept still to sign, and DqTermijnen draws its notices on other cases.

#### Scenario: the installed set

- **GIVEN** the Zuiddrecht example set installed
- **THEN** the case's documents read "4 van 7 beoordeeld" and the case has no decision

### Requirement: The premature notice of default sits on Kapvergunning Molenweg 4

The set SHALL seed the case "Kapvergunning Molenweg 4" (omgevingsvergunning, in behandeling) with a running statutory
term, and on that term the set's only premature notice of default (`validityStatus: premaat`), received while the term
runs, as DqTermijnen draws "Te vroeg: termijn loopt nog".

#### Scenario: the installed set

- **GIVEN** the Zuiddrecht example set installed
- **THEN** the deadline monitoring page lists the Molenweg 4 notice as premature, with no penalty

### Requirement: A closed Woo case is ready to archive

The set SHALL seed a closed Woo case ("Aanbesteding van het zaaksysteem", Afgehandeld) that meets the archive
readiness items DqArchiveren draws: ended by finishing (`endingAct: finish`), its result and its decision recorded on
the case (`result`, `decisions`), and the case type's retention facts (`archiveNomination: vernietigen`, an
`archiveActionDate` five years after the decision, `archiveStatus: nog_te_archiveren`). It SHALL hold seven final documents linked by `zaakinformatieobject`, the five the decision rules on assessed
with the decision's tally, and it SHALL carry every case field the archive dialog's facts name. It SHALL NOT be archived.

#### Scenario: the installed set

- **GIVEN** the Zuiddrecht example set installed
- **WHEN** a handler opens "Zaak archiveren" on that case
- **THEN** the dialog lists "Case ended", "Result recorded", "Decision made" and "Documents final: 7 of 7" as met, with
  nomination "vernietigen"
