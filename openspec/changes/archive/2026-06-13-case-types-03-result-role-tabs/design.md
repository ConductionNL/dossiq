## Architecture

This member is `kind: code` per ADR-032 — the centre of mass is Vue. It consumes
the `result-type` and `role-type` stores registered in member 01 and renders
schema-driven CRUD tabs. No declarative surface of its own beyond consuming
existing schemas.

### Frontend Changes — two new tab components in `src/views/settings/tabs/`

All tab components follow the same shape: accept a required `caseTypeId` prop,
fetch sub-entities scoped to that case type on mount, render a `CnDataTable`, and
drive CRUD through `CnFormDialog` (schema-driven) + `CnDeleteDialog`. Every
`await store.action()` is wrapped in try/catch with user-facing error feedback.
All user-visible strings via `this.t('procest', '...')` (keys declared in member 01).
Components imported from `@conduction/nextcloud-vue` only (never `@nextcloud/vue`),
and all imports listed in `components: {}`.

#### ResultTypesTab.vue
- Fetch result types where `caseType = caseTypeId` via the `result-type` store.
- Columns: `name`, `archiveAction` (badge: retain → green, destroy → red),
  `retentionPeriod` (formatted from ISO 8601 via `durationHelpers.js`),
  `retentionDateSource`.
- Add/Edit `CnFormDialog` fields: `name` (required), `description`,
  `archiveAction` (select: retain/destroy), `retentionPeriod` (ISO 8601 text with
  hint), `retentionDateSource` (select, 8 options).
- Delete via `CnDeleteDialog` (warn if referenced by closed cases).

#### RoleTypesTab.vue
- Fetch role types where `caseType = caseTypeId` via the `role-type` store.
- Columns: `name`, `description` (truncated) / `genericRole` (translated badge).
- Add/Edit `CnFormDialog` fields: `name` (required), `description`,
  `genericRole` (select: 8 generic role options).
- Delete via `CnDeleteDialog`.

### Modified: `src/views/settings/CaseTypeDetail.vue`

Add tab entries to the existing `NcTabPanel` navigation, after General and Statuses:

```
General | Statuses | Results | Roles | …(Properties | Docs | Decisions added in member 04)
```

Import and register the two new tab components; pass `caseTypeId` as a prop to
each. This member establishes the tab-integration framework — member 04 adds its
three tabs to the same panel. Verify no `CnDetailCard`-in-`CnDetailCard` nesting
(ADR-017 self-contained components).

### Reuse Analysis

| Capability | Reused Component | Notes |
|---|---|---|
| Sub-entity CRUD | `createObjectStore` + `CnFormDialog` + `CnDeleteDialog` | Schema-driven forms; no custom dialogs |
| Tab navigation | Existing `NcTabPanel` in `CaseTypeDetail.vue` | Add tab entries only |
| List display | `CnDataTable` via `CnIndexPage` pattern | Sortable, paginated |
| ISO 8601 duration display | Existing `durationHelpers.js` | Reuse for retentionPeriod |

## Decisions

1. **Schema-driven forms** — use `CnFormDialog` auto-generated from schema; no
   custom form layouts or validation components.
2. **Tab order** — Results before Roles (most frequently configured first).
3. **Integration framework here** — `CaseTypeDetail.vue` gains the tab-registration
   pattern in this member so member 04 only adds three more entries.
