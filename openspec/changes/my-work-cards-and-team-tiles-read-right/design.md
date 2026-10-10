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

## The recent tile gives way on the title

nextcloud-vue is making narrow object tables shrink their title column on their
own. The manifest side is to say which column gives way: the number and the date
get a width under `fixedLayout`, and the title carries `cn-cell--truncate` so it
ends in an ellipsis with the full title as tooltip.
