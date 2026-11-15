---
kind: code
depends_on: []
---

# Proposal: lists-and-dashboards-as-drawn

## Why

The 3 November design round redrew the dossiq lists and dashboards. r46 measured what live lacks on DqDashboard, DqTermijnen, DqZaken and DqProcesanalyse (R20/DQ-DESIGN-ROUND-GAPS.md, lane C):

- DqDashboard draws five signal tiles under the case tiles: Mijn open werk, Wachtrij van uw teams with Oppakken, Termijnsignalen, Taakherinneringen and Woo-termijnen. Live draws none.
- DqTermijnen draws "Verloopt het eerst" between the notices of default and the quarterly report. Live has no such card.
- DqZaken names a label filter "Label: wijk-noord". Live reads the schema's own field name.
- DqProcesanalyse ends with "Klachten: terugkerende patronen". Live has no complaint figures on the page.

## What changes

1. The simple structure's Dashboard overlay gains the five tiles as library record lists. Termijnsignalen reads `termSignalFrom` (dossiq#5191).
2. The term dashboard gains Ending first over `GET /api/deadlines/ending-first`, drawn only once that endpoint answers.
3. The case list names `tags` "Label".
4. The process analysis page gains the complaint pattern cards over `GET /api/complaints/patterns`, drawn only once that endpoint answers.

The two endpoints are backend work (R20/DQB-C/FOR-SIG.md). Until they exist their cards are not drawn.

## Not changed

- The in-page layout editing the boards draw as "Widgets schikken" and "Indeling aanpassen": layout editing lives under buildiq (Ruben, 18 October). The redraw asks for a decision.
- Side-state panels the boards draw beside the page: not decided yet.

## Rollback

Revert the commits.
