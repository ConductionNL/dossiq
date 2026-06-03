# Proposal: admin-settings

## Summary

Add V1 tabs (Results, Roles, Properties) to the case type detail view in admin settings. Each tab provides CRUD for its respective type definition linked to the parent case type.

## Motivation

The admin-settings spec defines MVP (General + Statuses tabs) as already implemented, and V1 (Results, Roles, Properties, Documents, Decisions tabs) as the next tier. Adding Results, Roles, and Properties tabs enables administrators to fully configure case type behavior including archival rules, participant role definitions, and custom properties.

## Affected Projects

- [x] Project: `procest` — Add 3 new tab components, update CaseTypeDetail

## Scope

### In Scope (V1)
- **REQ-ADMIN-009**: Results tab with result type CRUD and archival rules
- **REQ-ADMIN-010**: Roles tab with role type CRUD and generic role mapping
- **REQ-ADMIN-011**: Properties tab with property definition CRUD

### Out of Scope
- Documents tab (REQ-ADMIN-012) — deferred
- Decisions tab (REQ-ADMIN-013) — deferred

## Approach

Create three new Vue components: `ResultsTab.vue`, `RolesTab.vue`, `PropertiesTab.vue` in `src/views/settings/tabs/`. Each follows the same pattern as `StatusesTab.vue`: list items, add/edit inline, delete with confirmation. Update `CaseTypeDetail.vue` to include the new tabs.
