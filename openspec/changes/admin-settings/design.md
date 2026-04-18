# Design: admin-settings

**Status:** pr-created

## Architecture

Three new tab components following the same pattern as StatusesTab.vue: list items, inline edit, add form, delete with confirmation. Each fetches its own data via `useObjectStore().fetchCollection()` filtered by `caseType`.

## File Changes

| File | Change |
|------|--------|
| `src/views/settings/tabs/ResultsTab.vue` | New: Result type CRUD with archival rules |
| `src/views/settings/tabs/RolesTab.vue` | New: Role type CRUD with generic role dropdown |
| `src/views/settings/tabs/PropertiesTab.vue` | New: Property definition CRUD with format/requiredAtStatus |
| `src/views/settings/CaseTypeDetail.vue` | Add Results, Roles, Properties tabs |
