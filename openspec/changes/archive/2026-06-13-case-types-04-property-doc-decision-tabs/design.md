## Architecture

This member is `kind: code` per ADR-032 — the centre of mass is Vue. It consumes
the `property-definition`, `document-type`, and `decision-type` stores registered
in member 01 and the tab-integration framework from member 03. It adds no
declarative surface of its own.

### Frontend Changes — three new tab components in `src/views/settings/tabs/`

Same shape as the member-03 tabs: required `caseTypeId` prop, fetch on mount
scoped to that case type, `CnDataTable` + `CnFormDialog` (schema-driven) +
`CnDeleteDialog`, every `await store.action()` in try/catch with user-facing error
feedback, all strings via `this.t('procest', '...')` (keys declared in member 01),
imports from `@conduction/nextcloud-vue` only and listed in `components: {}`.

#### PropertiesTab.vue
- Fetch property definitions where `caseType = caseTypeId` via `property-definition` store.
- Columns: `name`, `propertyType` (format badge: text/number/date/datetime),
  `isRequired` (boolean icon), `defaultValue` (truncated if set).
- Add/Edit fields: `name` (required), `definition`, `description`,
  `propertyType` (select: text/number/date/datetime), `isRequired` (checkbox),
  `defaultValue`.

#### DocumentTypesTab.vue
- Fetch document types where `caseType = caseTypeId` via `document-type` store.
- Columns: `name`, `category`, `direction` (badge: incoming ← / internal ↔ /
  outgoing →), `isRequired` (boolean icon), `confidentiality`.
- Add/Edit fields: `name` (required), `description`, `category`,
  `direction` (select: incoming/internal/outgoing), `isRequired` (checkbox),
  `confidentiality` (select), `allowedMimeTypes` (tags input), `validFrom`, `validUntil`.
- Delete dialog explicitly states existing uploaded files are preserved.

#### DecisionTypesTab.vue
- Fetch decision types where `caseType = caseTypeId` via `decision-type` store.
- Columns: `name`, `isDraft` (badge), `publicationRequired` (boolean icon),
  `validFrom`, `validUntil`.
- Add/Edit fields: `name` (required), `description`, `isDraft` (checkbox),
  `publicationRequired` (checkbox), `validFrom`, `validUntil`.

### Modified: `src/views/settings/CaseTypeDetail.vue`

Add the three tab entries to the `NcTabPanel` framework established in member 03,
completing the order:

```
General | Statuses | Results | Roles | Properties | Docs | Decisions
```

Pass `caseTypeId` to each. Verify no `CnDetailCard`-in-`CnDetailCard` nesting
(ADR-017).

### Reuse Analysis

| Capability | Reused Component | Notes |
|---|---|---|
| Sub-entity CRUD | `createObjectStore` + `CnFormDialog` + `CnDeleteDialog` | Schema-driven; no custom dialogs |
| Tab framework | `CaseTypeDetail.vue` integration from member 03 | Add three entries only |
| Status name lookup | Existing statusType store | For `requiredAtStatus` selects |

## Decisions

1. **Schema-driven forms** — `CnFormDialog` auto-generated from schema.
2. **Files preserved on document-type delete** — deletion removes the requirement,
   not uploaded files; the delete dialog states this explicitly.
3. **Tab order** — Properties → Docs → Decisions (less frequently configured, after
   Results/Roles).
