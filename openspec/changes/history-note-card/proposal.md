# Proposal: history-note-card

## Why

Board DqZaakHistorie draws "Notitie toevoegen" as a card of its own under
"Historie van de zaak". Live drew the note form inside the history card.

## What changes

The Historie tab stacks the history card (`composer: "separate"`, it keeps
Add note) and a new `case-note-card` (`composer: "only"`, title "Add note",
the board's subtitle). Needs nextcloud-vue `feat/r24-timeline-composer-card`;
on an older library the history card draws no form and the note card draws the
whole timeline again, so this lands with that release.
