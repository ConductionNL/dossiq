# Proposal: lhs-severity-very-serious

## Why

Board DqHandhaving draws the LHS matrix with four consequence columns, A gering to D zeer ernstig. The lhsRecommendation
schema allowed three severities (gering, aanzienlijk, ernstig), so no advice could sit in a fourth column. Decided 24 Oct
(coordinator): add `zeer_ernstig`; the board's "matig" for B becomes the schema's "aanzienlijk" (board amendment).

## What changes

- Fragment `register.d/97-lhs-severity-very-serious.json`: lhsRecommendation 1.2.0 appends `zeer_ernstig` to `severity`
  with the label "very serious" (nl "zeer ernstig", lower case as its three siblings). Schema digest recorded.
- The advice page's matrix labels the fourth column "Gevolg D: zeer ernstig" and the notice says "gevolg D".
- Configuration only. A matrix offers the column once its lhsMatrix record holds `zeer_ernstig` on its severityAxis and
  the cells for it (shipped matrix and example data: DQ-DATA). Land together with that data: a recommendation for
  `zeer_ernstig` finds no cell on a matrix without it.
