## Why

Procest currently supports only flat case structures. Dutch municipalities frequently need hierarchical cases (deelzaken / sub-cases) where a parent case spawns child cases for parallel processing by different departments. For example, an "Omgevingsvergunning" parent case may require separate sub-cases for building, environmental impact, and fire safety — each with their own lifecycle, assignees, and deadlines. The data model already has `parentCase` and `relatedCases` fields on the case schema, and the ZGW rules service already validates `hoofdzaak` nesting (zrc-013), but there is no UI or service-layer support for creating, viewing, or managing sub-cases. This is a V1 feature per the case-management spec.

## What Changes

- Add a "Sub-cases" section to the case detail view showing child cases with status, assignee, and deadline
- Add a "Create Sub-case" action on the case detail view that pre-fills `parentCase` and constrains case type selection to the parent's `subCaseTypes`
- Show parent case breadcrumb navigation on sub-case detail views
- Add sub-case count and roll-up indicators (e.g., "3/5 sub-cases completed") to the parent case detail and case list views
- Extend the case store to support filtering by `parentCase` and loading sub-case trees
- Add validation: a sub-case cannot be created if the parent case is closed, and sub-case type must be in the parent case type's `subCaseTypes` list
- Ensure ZGW `hoofdzaak` / `deelzaken` mapping works bidirectionally in API responses

## Capabilities

### New Capabilities
- `deelzaak-support`: Sub-case creation, hierarchical case linking, parent-child navigation, sub-case roll-up indicators, and case type constraint enforcement for sub-cases

### Modified Capabilities
- `case-management`: Case detail view gains a sub-cases section, case list gains sub-case count column, case creation supports pre-filled parentCase context

## Impact

- **Code**: `src/views/cases/CaseDetail.vue` (sub-cases section, breadcrumb), `src/views/cases/CaseCreateDialog.vue` (parentCase pre-fill, type filtering), `src/views/cases/CaseList.vue` (sub-case count column), `src/store/` (case store sub-case queries), `lib/Settings/procest_register.json` (schema may need `subCases` array field for bidirectional linking)
- **APIs**: OpenRegister object queries with `parentCase` filter; ZGW `/api/zgw/zaken/v1/zaken` response enrichment with `deelzaken` list
- **Dependencies**: OpenRegister search/filter API for `parentCase` field queries
- **Testing**: Sub-case creation, nesting prevention (max 1 level), case type constraint enforcement, parent case breadcrumb, roll-up indicators
