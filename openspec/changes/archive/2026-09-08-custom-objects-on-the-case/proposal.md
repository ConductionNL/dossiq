---
kind: config
depends_on: []
---

# Proposal: custom-objects-on-the-case

Round 2 competitor analysis, row A27 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 1 on the
placement ladder: a schema that exists, a widget type that exists, and two
manifest entries. One noun: the things a case is about.

## Why

A case is about something: a building, a parcel, a vehicle, a tree. Dossiq
has the `caseObject` schema (`lib/Settings/dossiq_register.json`, the ZGW
ZaakObject) with `case`, `objectType`, `objectIdentification`, `objectUrl`
and `description`, and nothing reads it. No page lists a case's objects, no
form links one, and no page answers the question the other way round: which
cases are on this building.

All three competitors put objects on the case:

- Zaaksysteem: `xxllnc-zaken/round2/pages/Case-Relaties.md` (Gerelateerde
  objecten v1 and v2 as sections of the Relaties page, with Voeg toe).
- GZAC: `valtimo/round2/pages/Admin-Objecten.md` (a registered object type
  gets its own list page and search fields, the same mechanism as the case
  list).
- OpenCase: `opencase/round2/pages/EstateDetail.md` (an estate is a
  first-class object with a Cases tab).
- Dossiq baseline: findings.md row A27 (schema without a page or widget;
  object types are OpenRegister schemas already).

## What Changes

- `CaseDetail` gains an Objects tab in `case-panels`: an `object-list` over
  `caseObject` where `case` is the open case, with the columns object type,
  identification, description and link.
- A header action Link object of type `open-form` on `CaseDetail`, with the
  case passed in `props`, so you link an object without leaving the case.
- A `CaseObjects` index page over `caseObject`, reached from the Cases menu
  group as Objects, with a folder sidebar on object type and a search on
  the identification. Its rows open the case, so you find every case on one
  object.
- `caseObject.objectType` becomes a facet so the sidebar can group on it.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `case-management`: the case page lists its objects and links a new one;
  an objects index finds every case on an object.

## Impact

- `lib/Settings/dossiq_register.json`: `x-openregister-facet` on
  `caseObject.objectType`, version bump.
- `src/manifest.json`: page `CaseDetail`, widget `case-objects` and a tab in
  `case-panels`, header action `link-object`; page `CaseObjects` and its
  menu entry.
- `l10n/en.json`, `l10n/nl.json`: the new labels.
- E2E: `tests/e2e/case-objects.spec.ts` (new).
- Adjacent: `parties-on-the-case` (A03) and `contact-moments` (A17) wait on
  the same nextcloud-vue create-with-initial-data need; `case-actions-menu`
  (A33) orders the tab row this tab joins.
