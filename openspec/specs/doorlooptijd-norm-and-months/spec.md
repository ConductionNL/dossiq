# doorlooptijd-norm-and-months Specification

## Purpose
What dossiq does for doorlooptijd norm and months. The requirements were first written by the change `doorlooptijd-norm-and-months` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-doorlooptijd-norm-and-months/`) and cover: The processing-time chart draws the norm and names the months.

## Requirements

### Requirement: The processing-time chart draws the norm and names the months

The metrics answer SHALL carry `summary.normDays`, the rounded average term in days (start to deadline) of the cases
closed in the last twelve months, null when none had a term. The monthly processing-time chart SHALL draw a dashed line
at that value labelled "norm {value} days" (nl "norm {value} dagen") and write its month categories as short month
names. The page description SHALL name the twelve-month period it reports on, without a control to change it.

#### Scenario: Woo cases with a 28-day term

- **GIVEN** the cases closed in the last twelve months all had a 28-day term
- **THEN** the chart draws "norm 28 dagen" and the axis reads "nov", "dec", "jan", …
