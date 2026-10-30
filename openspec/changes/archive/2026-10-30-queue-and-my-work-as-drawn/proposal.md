# Proposal: queue-and-my-work-as-drawn

## Why

r34 measured three gaps between dossiq's case lists and their boards that are dossiq's own configuration:

- DqTeamwachtrij: the list columns were 96px (wait), 200px (type) and 140px (deadline); the board draws 60, 172 and 96,
  so the title column was 110px narrower than drawn.
- DqAanMijToegewezen: the board draws "10 van 10 ... · gesorteerd op urgentie" on the footer and explains the urgency in
  an info notice under the cards; live put the explanation on the footer line and drew no notice ("card: missing on
  live").
- DqAanMijToegewezen, DqZakenSorteren: the boards draw the footer 20px under the card grid, live 8px.

## What changes

- The team queue's `listConfig` widths: leading 60px, type 172px, deadline 96px.
- Assigned to me: `footerNote` "sorted by urgency"; `notice` (info, `placement: page`, on the page ground as DqContracten) with the board's explanation, including the line
  about covered work.
- app.css sets nextcloud-vue's `--cn-board-pager-gap` to 20px on dossiq's index pages.

## Depends on

nextcloud-vue fix/r34-board-card-grid-flush (the `--cn-board-pager-gap` hook) and fix/r34-board-queue-rows-as-drawn
(rows that wrap). Without them the widths and the hook change nothing visible beyond the wider title column.
