# The enforcement advice page follows its board

## Why

Board DqHandhaving draws an LHS enforcement advice as a record page: the
advised intervention as the pill, Enforcement as the breadcrumb, when it was
calculated, and beside the body the case it belongs to, the classification and
the history. The live page showed the same record as two data cards and a
related panel.

## What changes

- `LhsRecommendationDetail` gets the intervention pill, the breadcrumb, the
  meta line and a side column with Case (case and inspection), Violation class
  (behaviour, consequence, matrix version) and History. The related panel
  moves into the Case card, so the record cards span the body.

## Not in this change

The board's LHS matrix with the chosen cell outlined needs a matrix widget the
library does not have yet, and the "what now" step card needs a stage on the
record. Both are named to the coordinator.
