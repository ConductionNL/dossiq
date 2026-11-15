# Proposal: doorlooptijd-granularity

## Why

Board DqDoorlooptijd draws Maand, Kwartaal and Jaar beside "Januari tot en met september 2026": the average per month
over the year, grouped per month by default. The page read the switch as a window (one month, three months, a year),
so opening on Maand drew a single point (Ruben, 21 Oct: group per month, the window stays a year).

## What changes

`GET /api/doorlooptijd/metrics` takes `granularity` (week, month, quarter or year; default month) and groups the
averages in `summary.monthlyAvgDays` by it over the period window (default twelve months). The page's switch writes
`granularity` and opens on Maand; no widget sends a window of its own.
