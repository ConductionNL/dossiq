# The seats card as the board draws it

## Why
DqZaakBetrokkenen "Zetels" (board, as amended in design-system #356) has a subtitle, lists its pairs in one column, and
reads "Wacht op oppakken: Nee" and "Doorgegeven aan: Niemand" when nothing is pending. r25b drew no subtitle, three
columns, "Wacht op acceptatie" / "Overgedragen aan" and dashes.

## What changes
Config and Dutch words only: subtitle, `columns: 1`, `emptyValue` No / Nobody on the two hand-over fields, and the nl
words of the two labels. Needs nextcloud-vue feat/r25b-data-widget-empty-value-one-column; an older library ignores
`emptyValue` and keeps 200px columns. The board's footnote is `content.footnote`, which needs nextcloud-vue feat/data-widget-footnote.
