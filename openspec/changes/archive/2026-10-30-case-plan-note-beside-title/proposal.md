# The case plan's note sits beside its title

## Why
DqZaak and DqZaakMeer draw the Zaakplan card with "Mijlpalen en onderdelen ..." at the right end of the title row. The
simple case page set it as the widget's `subtitle`, a second line under the title, so r34 measured the first plan row
42.7px under the title where the board has 23.

## What changes
`case-plan-card` moves the line into `content.headerNote` (nextcloud-vue plan-header-note) and drops `subtitle`. Config
only; the l10n key is unchanged. nextcloud-vue before plan-header-note draws no line.
