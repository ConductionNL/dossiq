# Proposal: case-type-running-cases-fact

## Why

Board DqZaaktype's Gegevens card ends in "Lopende zaken 43"; the card stopped at the handling teams.

## What changes

`side-case-type-facts` gains a row "Running cases" (nl "Lopende zaken"): the cases of this case type without a final
status, counted with nextcloud-vue's facts `countFrom` (`feat/facts-entry-count` 5cd2be1a05). Before that release the
row is simply left out (an entry without a path reads empty and is hidden).

## Reach

Config only.
