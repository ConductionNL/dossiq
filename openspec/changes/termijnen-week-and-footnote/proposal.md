# Proposal: termijnen-week-and-footnote

## Why

DqTermijnen opens with the week, "Week 41 · alle teams", and closes the quarterly report with one line on how the
quarter went: "Kwartaal 3 van 2026: 72 zaken beslist, 67 binnen de wettelijke of verlengde termijn (93 %)." Live said
"Lopende termijnen, alle teams" and had no such line.

## What changes

The page description reads "{today|week} · all teams" (nextcloud-vue description-today-week). The quarterly report adds
`summaryRows`: one row with the quarter, the year, the terms decided, those within the term and the share, none when
nothing was decided. A record list under the quarterly table draws it as the board's line.

## Scope

DeadlineReportingService (quarterly report), TermijnDashboard manifest, l10n en + nl.
