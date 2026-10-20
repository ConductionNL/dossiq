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
