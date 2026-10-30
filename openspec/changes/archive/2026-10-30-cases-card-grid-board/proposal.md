# Proposal: cases-card-grid-board

## Why

Board DqZakenSorteren draws the case cards 242px wide, three beside the open side panel. On :8080 (24 Oct) the cards
were 367px, two beside the panel: the library's board-look card track has a 260px minimum (`--cn-card-grid-min`).

## What changes

Coordinator decision (24 Oct): dossiq only. Dossiq's index pages in the board look set `--cn-card-grid-min: 232px`;
the fleet default stays. Full width keeps four columns (probed live by injection on Assigned to me: 4 x 266px).
