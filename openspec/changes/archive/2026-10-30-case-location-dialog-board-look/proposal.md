# Proposal: case-location-dialog-board-look

## Why

COMPDIFF r19 on DqZaakLocatie flags the Add location dialog and every card in it: the dialog opens 640px wide where
the board draws 720, so the map, the layer list and the BAG and cadastre cards are all narrower and shifted; the
cards' titles are 16px at 600 over rows 37px below them where the board draws 17px at 700 over rows 8px below; the
layer list is 12px rounded with a 15px bold legend. The dialog also named the address field twice: a paragraph
"Adres zoeken" above the field's own label "Adres zoeken...".

## What changes

`CaseLocationDialog` asks CnDialog for the wizard width (720px) and drops its own paragraph label, so the field's
label names it once. It styles its content as DqZaakLocatie: 16px between blocks, the map beside a 200px layer list in
8px rounded frames, the legend 14px at 600, fact cards 12px rounded and 14px 16px in with a 17px title at 700 over
14px rows 8px apart, the hint 13px.

## Scope

One dossiq dialog. Colours stay Nextcloud variables.
