# Design: my-work-cards-and-team-tiles-read-right

## Late text uses the text token

`--color-error` is a colour for surfaces and icons in some themes and for text in
others. Under the NL Design System set it is the light tint #FFE7E7, and every
dossiq rule that colours TEXT with it reads at about 1.2:1 there. Nextcloud ships
`--color-error-text` for exactly this purpose. The card uses it, falling back to
`--color-error` where a theme does not define it.

About 46 other dossiq rules colour text with `--color-error`. They are inherited
and out of scope here; the root cause is the theme's mapping, which is better
fixed once in the theme than in every app.

## Dates through the column's own format

An object-list widget fetches no schema for its rows, so a schema's
`format: date` never reaches the cell. The column declares it itself, the same
way other dossiq lists already do for `sendDate`.

## The recent tile takes the board's shape

The board DqDashboard draws Recent geopend as a title with the case number under
it and the date on the right. CnDataTable's `secondary` draws exactly that second
line, so the number moves under the title and the table has two columns. The
title carries `cn-cell--wrap`, so a long title wraps rather than widening the
table past the tile.

Fixed layout with column widths was tried first and measured live: the tile hides
its header, a declared width only reaches the table through its header cells,
and the three columns split the tile evenly (114px each), which cut the title
to a third of the tile. nextcloud-vue is making narrow object tables shrink their
title on their own as well; this shape does not depend on that.
