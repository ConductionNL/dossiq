# Proposal: case-dialogs-start-flow-board

## Why

DqZaakDialogen draws Flow starten with the case type's flows under the legend "Deelproces", each with its one-line
description, the line "Alleen flows die het zaaktype toestaat." beneath them, and "Starten" as the confirm. The dialog
listed the flows without a legend or that line and confirmed with "Uitvoeren".

## What changes

- The flows sit in a fieldset with the legend "Sub-process" (Deelproces).
- Under the list: "Only flows the case type allows." (Alleen flows die het zaaktype toestaat.).
- The confirm reads "Start it" (Starten). What it does is unchanged.

The example set's Woo case type lists no startable flows, so the Start entry stays hidden on the board's case until
flows exist there (example data, not this change).
