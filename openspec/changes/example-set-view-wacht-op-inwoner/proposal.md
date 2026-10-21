# Proposal: example-set-view-wacht-op-inwoner

## Why

The saved-view boards (DqFilterBeheren, DqFilterBewerken, DqFilterDelen, DqFilterInMenu) open Pieter's view "Wacht op inwoner": the running cases of the team Woo en informatie that wait on the resident. The set only had his view "Termijn deze week", so the boards were measured against a view they do not draw.

## What changes

- A private view `pieter-wacht-op-inwoner` for Pieter on the case list. It filters on the group `zuiddrecht-woo-en-informatie`, `waitingOnApplicant`, not final, sorted by deadline.
- Pointing the filter boards at it in `boards.json` is for DQ-LISTS to decide.
