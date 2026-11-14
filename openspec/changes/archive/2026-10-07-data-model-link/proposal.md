---
kind: config
depends_on: []
---

# Proposal: data-model-link

Competitor gap register, rows Q11.32 "Can an administrator browse the data
model inside the product, every class with its fields and relations" (slug
`data-model-link`) and 11.17 "Custom object type management" (slug
`object-type-management-link`), `procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13. Both partial, owner dossiq,
size S. The register gives them two slugs; this lane opens one change,
because both are a link to the same OpenRegister page from a different
place. Recorded as a disagreement in the umbrella.

## Why

The answer exists in another app. Every dossiq schema is registered in
OpenRegister, whose admin pages list each schema with its properties and
types; object types on a case (`caseObject.objectType`) are OpenRegister
schemas too. dossiq offers no way in. The one cross-app link it has is
`AvgRegisterLink` to `/apps/openregister/#/avg` (`src/manifest.json:397`).

The best competitors in the register: iTop 3.2, `pages/schema.php` renders
189 classes with attributes, keys and lifecycle, verified live
(`_round4/compare/proposed-rows-batch5.md`); xxllnc Zaken,
`backend/zaken/src/zsnl_domains/case_management/entities/custom_object_type.py`
(`_round2/compare/M1-functionality.md`).

## What changes

- A menu entry Data model in the `integrations` section, pointing at
  OpenRegister's schema pages for the dossiq register.
- A Manage object types link on the Objects section of the case page and
  on `#CaseObjects`, pointing at the same page.
- Both are admin-gated: an ordinary handler does not see them.

## Ownership

dossiq builds two links. It consumes OpenRegister's register and schema
admin pages, shipped. The manifest pages and widgets are not browsable
anywhere; that half stays a gap and is noted, not built.

## ADRs

- Company ADR-110: a link that leaves the app leaves the navigation and
  renders in the Integrations section.
- Company ADR-023 rule 1: schema configuration is OpenRegister's own admin
  UI, not the consuming app's.

## Capabilities

- Modified: `admin-settings`: a Data model entry and a Manage object types
  link.

## Impact

`src/manifest.json` `menu[]` and two page notes; `tests/vitest/` menu
assertion. No PHP.
