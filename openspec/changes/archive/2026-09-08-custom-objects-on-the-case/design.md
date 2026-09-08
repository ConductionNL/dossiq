# Design: custom-objects-on-the-case

## Context

`caseObject` (`lib/Settings/dossiq_register.json`, version 1.0.0, the ZGW
ZaakObject) carries `case` (uuid, `$ref: case`, cascade on delete),
`objectType` (string, required), `objectIdentification` (string, the
object's identification), `objectUrl` (uri) and `description` (the relation
in prose). `SchemaSlugMap` knows the slug; `MigrateArchivalToOpenRegister`
and `BezwaarLegalHoldListener` touch it in passing. No page, widget or form
reads or writes it. `CaseDetail` (`src/manifest.json`) renders its panels in
the `tabs` widget `case-panels`; `case-locaties` is the `object-list` shape
this change copies, with `filter: {case: "@objectId"}` and no `rowRoute`.
The header action `log-hours` passes the case through `props`. The `Cases`
index uses a `folderSidebar` over a register schema; `case-identity` D2 uses
`x-openregister-facet` for a sidebar filter.

ADR-032 kind: **config**. One schema annotation, one widget, one tab entry,
one header action, one index page and its menu entry. No PHP.

## Goals / Non-Goals

**Goals:**

- The case page lists the objects the case is about.
- You link an object from the case with the case already known.
- You find every case on one object.

**Non-Goals:**

- Object types as OpenRegister schemas with their own pages (GZAC's
  Admin-Objecten). Object types are schemas already; a typed object page is
  the schema's own index. `objectType` stays a free string naming that
  schema or an external type.
- Fetching the object from `objectUrl`. The link is shown, not followed.
- Addresses and parcels. `case-location` (A28, spec `case-location`) owns
  those; an object here is anything that is not a location.
- A relation type vocabulary. Zaaksysteem v2 has none the handler picks;
  `description` carries the relation in prose.

## Decisions

### D1: the Objects tab is an `object-list` over `caseObject`

Widget `case-objects`, type `object-list`, icon `CubeOutline` (the schema's
icon), register `dossiq`, schema `caseObject`,
`filter: {case: "@objectId"}`, sorted on `objectType` ascending, columns
`objectType` (Object type), `objectIdentification` (Identification),
`description` (Description) and `objectUrl` (Link), `limit` 25, `emptyText`
"No objects linked to this case yet". It joins `case-panels` as the tab
Objects after Locations and, like its siblings, is absent from `layout`. No
`rowRoute`: a case object has no detail page and needs none; the row is the
record.

### D2: Link object is an `open-form` header action with the case in `props`

Header action `link-object` on `CaseDetail`, type `open-form`, register
`dossiq`, schema `caseObject`, `includeFields` `objectType`,
`objectIdentification`, `objectUrl`, `description`, `successMessage`
"Object linked to this case.", and `props: {case: "@objectId"}`, the shape
`log-hours` already uses. `case` is required on the schema; until
nextcloud-vue passes `props` into the form as initial data the handler
picks the case by hand, and the e2e asserts the saved object, not the
prefill.

### D3: `CaseObjects` is an index over `caseObject` whose rows open the case

The question "which cases are on this building" is answered by an index
over the link rows, not by a filter on the `Cases` page: a case does not
carry its objects, and an inverse-relation filter on the `Cases` index would
need either a denormalised `case.objects` list (two writers for one fact)
or a filter OpenRegister does not offer. So page `CaseObjects`, route
`/case-objects`, type `index`, title Objects, register `dossiq`, schema
`caseObject`, columns `objectType`, `objectIdentification`, `description`
and `case` (the case, `formatter: caseTitle`, added to `formatters` beside
`caseTypeName` if absent), `folderSidebar: {source: "facet", field:
"objectType", allLabel: "All objects"}`, `showViewAction: false`, and one
row action View case that navigates to `CaseDetail` with the row's `case`
as the id. Menu entry Objects, icon `CubeOutline`, in the Cases group after
All cases. The search box on the index searches `objectIdentification` as
it searches every string property.

### D4: `objectType` is a facet

`caseObject.objectType` gains `x-openregister-facet: true` and the schema
version goes to 1.1.0. Nothing else on the schema moves: D13 asks for
English identifiers, and the five it has are English.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| The list, the form, the index | declarative, manifest entries | `object-list`, `open-form` and `index` exist. |
| The sidebar grouping | declarative, a facet annotation | OpenRegister computes facets. |
| Nothing | imperative | No behaviour needs code. |

## Seed Data

- `lib/Settings/register.d/46-demo-cases-english.json`: two case objects on
  one demo case (a building by BAG id and a vehicle by licence plate) and
  one on another case sharing the building's identification, so the tab
  shows rows and the index shows the same object on two cases.

## Risks / Trade-offs

- `objectIdentification` is a free string. Two handlers writing the same
  building as `0363100012345678` and `0363 1000 1234 5678` produce two
  objects on the index. Accepted for rung 1; a picker over a register is
  the BAG adapter's job (`bag-register-adapter`).
- The interim passes `case` through `props`. If the form does not prefill,
  the handler picks the case by hand until the nextcloud-vue change lands.
- `formatter: caseTitle` may not exist. If the index cannot format `$ref`
  columns, the column shows the id until triage #8 (Tier D06) lands in
  nextcloud-vue; the View case action still opens the right case.
