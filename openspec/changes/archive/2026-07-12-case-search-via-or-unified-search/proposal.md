---
kind: config
---

# Surface cases in Nextcloud unified search via the OR search leaf

## Why

OpenRegister ships the single fleet-wide Nextcloud unified-search provider (`openregister_objects`, `lib/Search/ObjectsProvider.php`). Leaf apps do not register their own `OCP\Search\IProvider` — they participate by (a) flagging schemas `searchable: true` in their register definition and (b) claiming `(register, schema)` pairs in the deep-link registry so results get URLs, icons and display names.

Procest today does neither for its main register: `lib/Settings/procest_register.json` (83 component schemas) contains **zero** `searchable` flags, and `src/manifest.json` `deepLinks` covers only `case` and `task`. Result: a behandelaar typing a zaak number or subject into the Nextcloud search bar finds nothing. "Fast, filterable search" is a top-12 evidence-ranked user wish (mintlab #2165/#1646, gemma-zaken filter issues, 2026-07 market research), and the OR leaf is already built — procest only has to opt in.

## What changes

- `lib/Settings/procest_register.json` — add `"searchable": true` at schema level to the five schemas whose objects have a reachable detail surface: `case`, `task`, `bezwaar`, `voorstel`, `beroep`. No other schemas are flagged (decisions, complaints and all settings/config schemas have no standalone detail route; flagging them would produce dead search results).
- `lib/Settings/procest_register.json` — bump `info.version` so the register repair step re-imports the changed schemas on upgrade (OR register import is version-gated).
- `appinfo/info.xml` — bump the app version for the same reason.
- `src/manifest.json` — extend `deepLinks` with `bezwaar → /apps/procest/bezwaren/{uuid}`, `voorstel → /apps/procest/voorstellen/{uuid}`, `beroep → /apps/procest/beroepen/{uuid}` (display names Bezwaar, Voorstel, Beroep). `case` and `task` entries already exist and stay as-is.

## Impact

Config-only (register JSON + manifest + version bumps). No PHP, no Vue components. Security posture is inherited from the OR provider's documented contract: all results flow through `ObjectService::searchObjectsPaginated(_rbac: true, _multitenancy: true)`, so RBAC scoping (role-routing-via-or-rbac), tenant isolation and the published predicate apply — confidential cases a user cannot read do not appear, and excerpts are derived from the redacted rendered object. The provider only narrows (searchable flag), never widens.

## Capabilities

### New Capabilities
- `case-search-via-or-unified-search` — procest business objects (cases, tasks, bezwaren, voorstellen, beroepen) are findable from the Nextcloud unified search bar via the OR search leaf, deep-linking to their procest detail pages.
