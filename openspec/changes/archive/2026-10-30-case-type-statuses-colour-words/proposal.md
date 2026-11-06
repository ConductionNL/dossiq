# Proposal: case-type-statuses-colour-words

## Why

DqZaaktypeStatussen writes a status's list colour as a word ("Blauw", "Oranje", "Groenblauw"). The Statuses table drew it
as a grey status pill with the raw value, which also made every row 44px where the board draws 40.5px.

## What changes

The colour column takes the library's `label` widget with the palette's labels (the ones the status form already uses,
plus Teal / Light teal, nl "Groenblauw" / "Licht groenblauw"), so the list says the colour in words, without a pill.
