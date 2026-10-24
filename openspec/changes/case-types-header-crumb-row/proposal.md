# case-types-header-crumb-row

## Why

The Case types list (board dossiq/DqZaaktypen) carried both a count subtitle ("54 case types") and a breadcrumb with a
meta note that already gives the count. With a subtitle under the title the header moved the breadcrumb to the right,
under the buttons, and the list started lower. The board draws the breadcrumb row straight under the title, with the
count in its note, and no subtitle.

## What changes

- The simple profile's Case types page drops `countSubtitle`; `headerMeta` keeps the count.
