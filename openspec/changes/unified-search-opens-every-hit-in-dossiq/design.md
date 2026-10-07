# Design: unified-search-opens-every-hit-in-dossiq

## Screen

The board is `DqKop` ("Werkplek bovenbalk"): the workplace top bar with one
search field, placeholder "Zoek zaken, documenten of contacten". That field is
the Nextcloud header search as thematiq themes it, not a dossiq component. The
board's placeholder sets the scope this change delivers: a hit on a case, a
document or a contact opens a dossiq page. No board draws the result list; it
is Nextcloud's.

## D1 · Three groups, one rule each

| group | rule | example |
| --- | --- | --- |
| has a detail page in `src/manifest.json` | a `deepLinks` entry to that page | `bezwaarDecision` to `/apps/dossiq/bezwaar-decisions/{uuid}` |
| belongs to a case, no page | a `deepLinks` entry to the case, through the field that holds the case uuid | `caseDocument` to `/apps/dossiq/cases/{case}` |
| neither | `searchable: false` on the schema | `decisionType`, `routingRule` |

The 11 detail-page schemas without a deep link at c666f7c8b: `bezwaarDecision`,
`adviesAanvraag`, `wmsLayer`, `tenant`, `casetransfer`, `workflowTemplate`,
`statusRecord`, `lhsRecommendation`, `bezwaaradviescommissie`,
`bacAdviceRequest`, `caseType`.

Case-owned schemas hold the case in one of four fields (51 schemas): `case` (27,
among them `caseDocument`, `contactmoment`, `aanvullingsverzoek`, `objection`,
`zaakinformatieobject`), `caseId`, `caseRef` or `parentCase`. The template
names the field: `/apps/dossiq/cases/{caseId}`. OpenRegister's
`DeepLinkRegistration::resolveUrl()` replaces any top-level scalar key, so no
openregister change is needed.

## D2 · What a builder must check per case-owned schema

A deep link through a field only works when the field holds a bare case uuid.
`caseRef` in particular may hold a URL or an identifier. For each schema, read
one seeded object. A bare uuid gets the deep link. Anything else gets
`searchable: false` and a line in tasks.md naming the field and what it held.

## D3 · Logs stay out, even when they name a case

`stateMachineLog`, `aiAuditEntry`, `sociaalDomeinAuditLog`, `mandateUsage` and
`zaaksysteemMapping` name a case, but a hit on one is noise, and an audit log
of social-domain work is not something to surface by typing a surname. They
are flagged `searchable: false`.

## D4 · The flag needs a re-import

OpenRegister imports a schema definition only when the register version
advances (requirement "Version-gated re-import"). Each touched fragment and
`dossiq_register.json` bump `info.version`, the app version in
`appinfo/info.xml` bumps, and `php tools/schema-version-digests.php` refreshes
the digests `SchemaVersionFloorTest` pins.

## D5 · The guard

`tests/vitest/searchableSchemas.spec.js` pins three flagged slugs today. It
changes to: every schema across `dossiq_register.json` and `register.d/` is
either a `deepLinks.schemaSlug` or `searchable: false`, and every
`urlTemplate` names a manifest route with each `{field}` a property of that
schema. The explicit `searchable: true` on `case`, `objectionProceeding` and
`beroep` stays.

## Risks

- A contact (`brpPerson`) is already a hit today. This change does not widen
  what a person can find: results stay behind openregister's RBAC and the
  active organisation. It only changes where a hit opens.
- An opted-out schema that a handler did search for disappears from the
  results. The list in tasks.md is the review point; anything a handler names
  on a page goes in group 1 or 2.
