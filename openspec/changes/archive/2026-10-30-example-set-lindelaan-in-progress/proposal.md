# Proposal: example-set-lindelaan-in-progress

## Why

The DqZaak boards draw every case-page screen on one Woo case in behandeling ("Wat nu? Stap 2: in behandeling"), and
DqZaakContact draws four contact moments on it. The screens were measured against cases made at runtime: one in
progress without contact moments, others with contact moments but no stage or a closed one. The Zuiddrecht example set
already seeds the board's case, "Verlichting fietspad Lindelaan", but at Besluit with its publication ready.

## What changes

The example set seeds `woo-lindelaan` in "Beoordeling ontvankelijkheid" (role in-progress), without
`wooPublicationStatus`, and with four contact moments shaped as the board's: a telephone call that took the request,
an explanation and a reply through Mijn Zuiddrecht, and an e-mail adding to the request. People come from the cast.

The documents are seeded as the case's documents are found: `informatieobject` rows with `zaakinformatieobject` rows
on the case (the earlier `document` rows named no case, so the case counted none). Seven, four assessed, as
DqZaakDocumenten draws "4 van 7 beoordeeld"; three are new fictional files. The recorded decision and the notice of
default go: DqZaakBesluiten draws the decision as a concept, and DqTermijnen draws its notices on other cases.
