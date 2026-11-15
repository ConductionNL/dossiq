# Proposal: contacts-list-columns-as-drawn

## Why

Board DqContacten sizes its columns: Naam 250px, so a resident's address under the name reads in full ("Kerkplein 3,
Zuiddrecht"), BSN 211px, Woonplaats 157px, and Omschrijving takes the rest, sortable. The list split the width
evenly: the address was cut ("Kerkplein 3, Zuiddre...") and the description too.

## What changes

The Contacts columns get the board's widths, and Omschrijving sorts.

## Scope

Config only (`src/manifest.json`, Contacts columns).
