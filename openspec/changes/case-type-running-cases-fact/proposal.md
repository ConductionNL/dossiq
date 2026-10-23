# Proposal: case-type-running-cases-fact

## Why

Board DqZaaktype's Gegevens card ends in "Lopende zaken 43"; the card stopped at the handling teams.

## What changes

`side-case-type-facts` gains a row "Running cases" (nl "Lopende zaken"): the cases of this case type without a final
status, counted with nextcloud-vue's facts `countFrom` (`feat/facts-entry-count` 5cd2be1a05). LAND TOGETHER: an older
library reads an entry without a path as the whole record.

## Reach

Config only.
