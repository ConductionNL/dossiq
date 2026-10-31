# Proposal: case-type-statuses-tiles

## Why

Board DqZaaktypeOverzicht draws the Statussen card as two columns of framed tiles, each with the status's colour dot,
its name and what it marks at the end ("Start", "Termijn opgeschort", "Eind"), with a line under them: colour and
order apply in lists, on the workboard and in the portal. The live card drew tall cards with a bold name and an "End"
pill only, and no colour.

## What changes

- `case-type-statuses` draws `presentation: "rows"` with `tiles: true`, a `dot` on the status `colour` (the NL Design
  System palette tokens the case index uses) and `flags`: Start (role intake), Term suspended (waits on the
  applicant, which suspends the term) and End (isFinal). The End pill goes; End is a flag now.
- A footnote under the tiles.
- l10n: "Term suspended", the footnote (en + nl, json + js).

## Reach

Config only. The tiles need nextcloud-vue `feat/record-list-row-tiles` 0da126842a; before it the statuses read as
plain rows with dot and flag (dot and flags are in 2.129.0). No schema change.
