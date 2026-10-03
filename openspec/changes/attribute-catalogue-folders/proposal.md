---
kind: config
depends_on: []
---

# Proposal: attribute-catalogue-folders

Competitor gap register, row 11.23 "Catalog with folders for case types,
attributes, templates" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner dossiq,
size S. Re-read on 2026-09-13: the gap stays; `#CaseTypes` folders on
`category`, `propertyDefinition` has no category.

## Why

Case types are in folders; attributes are a flat list. `caseType.category`
is facetable and `#CaseTypes` carries a `folderSidebar` on it
(`case-type-authoring-extras`, archived 09-08). `propertyDefinition`
(`name`, `definition`, `propertyType`, `enumValues`, ...) has no category,
so a municipality with two hundred attributes scrolls. Templates are
filinq's library and dossiq only references them.

The best competitor in the register: xxllnc Zaken,
`frontend-mono/apps/main/src/modules/catalog/Catalog.types.ts`
(`_round2/compare/M1-functionality.md`).

## What changes

- `propertyDefinition.category`, a facetable string, and a `folderSidebar`
  on it on the property definitions index, in the shape `#CaseTypes` uses.
- The case type's property picker groups attributes by category.
- Templates: the template picker shows filinq's categories when filinq's
  library exposes one; until then it stays flat. No dossiq category on
  templates.

## Ownership

dossiq builds the attribute half. The templates half is consumed from
filinq's template library; the register names no slug for a template
category, so the umbrella lists it as an open question for filinq.

## ADRs

- Company ADR-031: a category is a declared, facetable property.
- Company ADR-096: the index is a `CnIndexPage` configured by the manifest.

## Capabilities

- Modified: `property-definition-management`: attributes are in folders.

## Impact

`lib/Settings/dossiq_register.json` (`propertyDefinition.category`);
`src/manifest.json` property definitions index and the case type property
picker; `tests/vitest/caseTypeAuthoringManifest.spec.js`; one e2e spec.
