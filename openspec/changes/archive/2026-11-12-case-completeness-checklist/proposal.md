# Proposal: case-completeness-checklist

## Why

Board DqZaakDocumenten draws "Volledigheid van de aanvraag": the document list from the case type, each item ticked
or missing, "2 van 5 aanwezig, 3 ontbreken" and a verdict. The case type had no such list and no endpoint answered
it, so the card stayed hidden. Board DqSociaalDomein's "Doel toevoegen" posts to `/api/family-plans/@object.planId/goals`,
and the family plan answer carried the plan id only inside `plan`, which the URL tokens do not read.

## What changes

- The case type declares `intakeRequirements.completenessItems`: `{ key, label }` plus what answers the item: a
  case document by `documentType` (its informatieobjecttype) or by `documentTitleContains` (a word in its title or
  file name), or a case `field` (dot path) with an optional `evidence` text. Schema fragment
  `register.d/99-case-type-completeness-items.json`.
- New `GET /api/cases/{caseId}/completeness` (case read access) answers
  `{ items: [{ key, label, present, evidence }], present, total, missing, verdict }`; a case type without a list
  answers `{ items: [] }`. A document the reader may not read counts as present without its name.
- The Woo request case type (version 2) declares the five items the board draws. Its `requiredBeforeCreation` is set
  to `[]` explicitly, which is what it was while it declared no intake requirements.
- The family plan answer carries `planId` at the top level (`null` without a plan).

## Scope

Generic: any case type that declares a list. Procedures stay configuration.
