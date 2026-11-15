# Proposal: case-type-fields-in-list-no-filter

## Why

Board DqZaaktypeVelden draws the Velden table with a funnel on Veld, Soort, Invullen and Herkomst, and none on "In de
lijst". Live drew one there too: its 26px came out of the Veld column, so field names broke mid-word ("Welstandsge bied",
"Situatieteke ning"), the rows grew, and the card under the table sat 52px low (r34: card "Wie wat ziet en invult" y
+52.3, table width 849 > 831.6).

## What changes

- The "In the list" column of the case type fields table sets `filterable: false`.

## Amended (r36)

The board does draw the funnel on "In de lijst" (the Filteren op In de lijst button at 1063, inside the table's
849px scroll width, past the 754px card edge, so the board picture cuts it off). The mid-word breaks came from the
reorder grip: a floated grip does not count towards the cell's narrowest width. nc-vue
fix/r36-reorder-grip-keeps-name-width holds that width, and the column keeps its filter again.
