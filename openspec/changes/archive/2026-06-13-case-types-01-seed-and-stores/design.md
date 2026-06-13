## Architecture

### Declarative-vs-imperative decision

This member is `kind: config` per ADR-032. Everything it touches is declarative:

- **Seed data** is mock objects in `procest_register.json`, materialised by the
  standard `ConfigurationService::importFromApp()` repair step (ADR-001). No
  imperative seeding code is written.
- **Store registration** is a declarative `registerObjectType()` call per
  sub-entity — the createObjectStore platform pattern (ADR-001/ADR-015), not a
  bespoke data-fetching service.
- **Translations** are key/value declarations in `l10n/*.json`.

The store registration lives in `src/store/store.js` (a `.js` file). Per ADR-032
this is declarative registration tightly coupled to the schema declaration —
"declare the data the feature consumes" — and is the natural home for the
expand step of the expand-then-contract migration. No business logic is added.

### Data Layer

All case type sub-entities are stored as OpenRegister objects linked to their
parent case type via the `caseType` reference field:

```
caseType (slug: e.g. ct-omgevingsvergunning)
├── statusType[]       — 3–4 ordered lifecycle phases (caseType: <slug>)
├── resultType[]       — 3 outcome types with archival rules (caseType: <slug>)
├── roleType[]         — 3–4 participant role definitions (caseType: <slug>)
├── propertyDefinition[] — declared, seeded empty (consumed by member 04)
├── documentType[]     — declared, seeded empty (consumed by member 04)
└── decisionType[]     — declared, seeded empty (consumed by member 04)
```

All six schemas are already registered in `procest_register.json` and mapped in
`SettingsService::SLUG_TO_CONFIG_KEY`. No schema migrations are required.

Sub-entity queries use `objectStore.getObjects({ caseType: uuid })` on the
frontend (consumed by members 03/04).

### Store registration (`src/store/store.js`)

Register five additional entity types (if not already registered):

```js
objectStore.registerObjectType('result-type', 'resultType', 'procest')
objectStore.registerObjectType('role-type', 'roleType', 'procest')
objectStore.registerObjectType('property-definition', 'propertyDefinition', 'procest')
objectStore.registerObjectType('document-type', 'documentType', 'procest')
objectStore.registerObjectType('decision-type', 'decisionType', 'procest')
```

Type names: kebab-case per ADR-015 store registration pattern. Verify no duplicate
registrations exist across `OBJECT_TYPES` or `ENTITY_STORES`.

### Seed Data

Defined in `lib/Settings/procest_register.json` under `components.objects[]`
with `x-openregister: { type: "mock" }`. Uses `@self` envelope with slug-based
idempotency. Cross-entity references (e.g., `caseType: "ct-omgevingsvergunning"`)
use slug strings that `ImportHandler` resolves to UUIDs on first import.

**4 caseType objects** — Omgevingsvergunning (P56D), Subsidieaanvraag (P13W),
Klachtbehandeling (P6W), Bezwaarschrift (P6W). All `isDraft: false`,
`validFrom: "2026-01-01"`, with realistic Dutch descriptions, purpose, trigger,
subject, confidentiality, origin, and responsible department.

**14 statusType objects** (3–4 per case type) with `caseType` slug references and
exactly one `isFinal: true` per case type so each passes `validatePublish()`
(consumed by member 02).

**12 resultType objects** (3 per case type) with `archivalAction`
(`blijvend_bewaren` / `vernietigen`) and `archivalPeriod` (ISO 8601, e.g. `P20Y`).

**13 roleType objects** (3–4 per case type) with Dutch role names.

Full object payloads are reproduced verbatim from the parent `case-types` change
design (the four case types, their statuses, results, and roles).

### Seed-data acceptance

- Fresh install shows 4 case types in admin settings list; each has status types,
  result types, and role types visible.
- Re-running the repair step does NOT duplicate any objects (slug idempotency).
- Each seeded case type passes the member-02 publish validation (≥1 statusType,
  ≥1 `isFinal` status, `validFrom` set).

### i18n

Add the user-visible string keys the later UI members consume (key == value in
`en.json`, Dutch values in `nl.json`), at minimum: tab labels (Results, Roles,
Properties, Docs, Decisions), form field labels (Archive action, Retention period,
Generic role, Property type, Is required, Default value, Category, Direction,
Allowed MIME types, Publication required), and archivalAction options (Retain
permanently, Destroy). Verify zero key gaps between `en.json` and `nl.json`.

## Reuse Analysis

| Capability | Reused Component | Notes |
|---|---|---|
| Seed import | `ConfigurationService::importFromApp()` repair step | Standard ADR-001 path; no custom seeding code |
| Store registration | `createObjectStore` / `registerObjectType` | Platform pattern; no custom stores |
| Slug idempotency | `ImportHandler` slug→UUID resolution | Re-import does not duplicate |

## Decisions

1. **No new schemas** — all six sub-entity types are pre-defined in ADR-000 and
   already registered in `procest_register.json`.
2. **Declare-first** — store registration + seed + i18n land before any consumer
   UI or backend guard (ADR-032 expand-then-contract). Members 02–04 merge on top.
3. **Seed scope** — flat properties only, per the current `ImportHandler`
   limitation; relations resolved by slug at import time.
