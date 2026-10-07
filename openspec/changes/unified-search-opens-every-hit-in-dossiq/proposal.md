---
kind: config
depends_on: []
---

# Proposal: unified-search-opens-every-hit-in-dossiq

The dossiq half of matrix rows 9.1 (global quick search) and 9.12
(platform-level unified search integration). The delivered half is
openregister's `unified-search-index` (all 12 tasks ticked, openregister#3899):
the Nextcloud search in the header now returns OpenRegister objects across
every searchable schema, each from its own register.

## Why

A handler types a name in the Nextcloud search and gets dossiq hits. Many of
them open a JSON document.

OpenRegister imports every schema as searchable unless it opts out
(`Schema::$searchable = true`, measured 2026-09-08 in
`case-search-via-or-unified-search`). dossiq declares 167 schemas and opts none
out. It claims a deep link for five: `case`, `objectionProceeding`, `beroep`,
`brpPerson` and `kvkCompany`. A hit on any other schema falls back to
`openregister.objects.show`, the JSON endpoint. Counted on development at
c666f7c8b:

| group | schemas | what a hit opens today |
| --- | --- | --- |
| a dossiq detail page, no deep link | 11 (`bezwaarDecision`, `adviesAanvraag`, `caseType`, ...) | JSON |
| belongs to a case, no page of its own | 51 (`caseDocument`, `contactmoment`, `aanvullingsverzoek`, ...) | JSON |
| configuration, types, queues and logs | 100 (`decisionType`, `routingRule`, `stateMachineLog`, ...) | JSON, and it should not be a hit at all |

The main spec already says every detail page SHALL have a deep link. Eleven do
not. This change closes that and decides the other two groups.

## What changes

- A deep link for each of the 11 schemas that has a dossiq detail page.
- A record that belongs to a case and has no page of its own opens its case.
  OpenRegister's deep link template takes any top-level scalar field, so
  `/apps/dossiq/cases/{case}` works without new code in openregister.
- Every other schema is flagged `searchable: false`, so it is not a hit.
- A vitest guard: every schema in the register is either deep-linked or opted
  out. A new schema that does neither fails the test.

## Not in this change

- Engine tasks. They live in openregister's task engine, outside the object
  index, and no deep link shape addresses them. That half is openregister's
  (`CROSS openregister` in the hand-back). `/tasks/:id` is ready for the day a
  provider exists.
- The search field itself. The board `DqKop` draws it in the workplace top bar,
  which is the Nextcloud header as thematiq themes it. dossiq does not own the
  header.
- Quick links such as "My open cases" inside the search. Nextcloud's unified
  search has no slot for them.

## Rows

- 9.1 Global quick search. `built.change` moves here; the note keeps
  `openregister/unified-search-index` named.
- 9.12 Platform-level unified search integration. Same.

## Impact

`src/manifest.json` (`deepLinks`), `lib/Settings/dossiq_register.json` and the
`lib/Settings/register.d/*.json` fragments (`searchable: false`, version
bumps), `appinfo/info.xml` (version), `tests/vitest/searchableSchemas.spec.js`.
No PHP, no new page.
