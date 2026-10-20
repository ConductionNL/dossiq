# Proposal: setup-wizard-board

## Why

Board DqInstellen draws the example-data step's note ("Voorbeeldgegevens gebruiken alleen fictieve personen en
organisaties.") as an info card under the choices. dossiq drew it as a muted line.

## What changes

The demo-data setup step sets `noteStyle: "card"`, which nextcloud-vue's setup wizard draws as an info note card.
