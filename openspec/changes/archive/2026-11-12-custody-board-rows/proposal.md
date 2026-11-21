# Who held the case, as rows

## Why

COMPDIFF r25b DqZaakArchivering (24 Oct): the Archiving tab's custody part drew 24px headings over loose text lines, the backfill's stored English reason ("Opened by the custody backfill from the case as it stood") in a Dutch UI, and dates with the year. The board draws Bewaring as rows: a round mark with the unit's initials, the unit over "In handen sinds 3 okt" or "Van 3 okt 14:10 tot 3 okt 14:18 · reden", and a pill: "Houder nu" for the current holder, "Overgedragen" for the others.

## What changes

- CaseCustodyPanel: holding rows with initials, unit, meta line (period, handler, reason) and a pill; section headings 15px/600; the backfill reason through the translation; dates as day and month, a closed holding's ends with the time.
- Every holding keeps both ends; nothing is hidden.

## Impact

CaseCustodyPanel.vue, l10n (3 keys). No schema or route change. With r25/custody-pane-card the panel stands in the titled Bewaring card.
