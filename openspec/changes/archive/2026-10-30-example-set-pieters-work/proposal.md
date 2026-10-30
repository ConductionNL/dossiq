# Proposal: example-set-pieters-work

## Why

The work boards (DqMijnWerk, DqTaken, DqTaak) draw Pieter Jansen's own work: an objection about a parking fine on the Havenstraat with the hearing to plan, a Woo request about parking permits in the town centre whose term runs out, a tree-felling permit on the Parkweg, and a Woo request about event permits still in the team's pool. The task list shows every priority, a task done and a task nobody has picked up. The Zuiddrecht set held none of these cases, and its one task sat on a tree-felling permit.

## What changes

- Four cases for those boards: `bezwaar-parkeerboete-havenstraat` (Bezwaar), `woo-parkeervergunningen-binnenstad` (Woo, received 28 days ago), `woo-vergunningen-evenementen` (Woo, unassigned) and `kapvergunning-essen-parkweg` (omgevingsvergunning).
- The task "Hoorzitting inplannen" moves onto the objection case. There are five more tasks: urgent, normal, available, in the pool (`candidateGroups`), and done with priority low.
- The objection's case type lookup also names `icon: Gavel`, because the on-request objection package ships a second case type with the identifier `bezwaar`.
