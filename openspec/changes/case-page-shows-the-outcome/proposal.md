---
kind: code
---

# Proposal: case-page-shows-the-outcome

## Why

A resident whose case has ended wants to know what was decided and why. dossiq projects both on its `mijnZaken` collection as `resultPublicLabel` and `resultPublicDescription` (dossiq#3143), so Mijn zaken shows the outcome in words. The case page does not: portaliq's case page shows the "Uitkomst" block only for a collection that names its outcome fields (portaliq `case-outcome-on-the-case-page`, `outcome: {labelField, descriptionField}`). dossiq does not name them yet, so the board ZaakAfgerond's outcome never appears.

## What changes

1. The `mijnZaken` collection declares `outcome: {labelField: resultPublicLabel, descriptionField: resultPublicDescription}`. Both fields are already projected; portaliq drops a field the collection does not project.

## Rollback

Revert the commit; the case page then shows no outcome, as before.
