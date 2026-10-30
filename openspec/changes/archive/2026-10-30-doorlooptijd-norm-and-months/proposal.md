# Proposal: doorlooptijd-norm-and-months

## Why

DqDoorlooptijd opens with the period it reports on ("januari tot en met september 2026 ·"), draws a dashed "norm 28
dagen" over the processing time per month and names the months "jan", "feb". Live had no period, no norm line and
axis labels "2025-11".

## What changes

- The page holds its twelve-month period as a page filter without a control and names it in the description
  (`{filter.period}`); the monthly chart sends it to the endpoint.
- The metrics answer carries `summary.normDays`: the average term (start to deadline) of the cases closed in the last
  twelve months, from a new `_normDays` on each enriched case.
- The monthly chart draws a reference line at `summary.normDays` ("norm {value} days") and writes month names.

Needs nextcloud-vue page-filter-without-control and chart-reference-line-from-answer.

## Scope

CaseEnricher, NormAverage (new), DoorlooptijdService, the Doorlooptijd page, the vendored schema, l10n.
