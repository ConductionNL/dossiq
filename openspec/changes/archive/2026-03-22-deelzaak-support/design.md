## Context

Procest manages cases as OpenRegister objects with a `case` schema. The schema already includes `parentCase` (UUID reference) and `relatedCases` (JSON-encoded array) fields, both marked `visible: false`. The case type schema includes a `subCaseTypes` array field listing allowed sub-case type references. The ZGW rules service (`ZgwZrcRulesService`) already validates `hoofdzaak` nesting (zrc-013): a case cannot be its own parent, and nested sub-cases (deelzaak of a deelzaak) are prohibited.

Currently, the frontend has no awareness of parent-child case relationships. The case detail view (`CaseDetail.vue`) shows status timeline, participants, deadlines, results, and activity, but no sub-cases section. The case creation dialog (`CaseCreateDialog.vue`) does not support pre-filling `parentCase` or filtering case types by the parent's `subCaseTypes`.

## Goals / Non-Goals

**Goals:**
- Enable users to create sub-cases from a parent case detail view
- Show sub-cases as a dedicated section on the parent case detail page
- Provide breadcrumb navigation from sub-case back to parent
- Display sub-case progress roll-up on parent case and in case lists
- Enforce case type constraints: sub-case type MUST be in parent's `subCaseTypes`
- Enforce single-level nesting: sub-cases cannot have sub-cases (existing ZGW rule)
- Maintain ZGW API compatibility for `hoofdzaak` / `deelzaken` fields

**Non-Goals:**
- Multi-level nesting (grandchild cases) — prohibited by ZGW zrc-013c
- Automatic case lifecycle cascading (closing parent does not auto-close sub-cases)
- Sub-case task aggregation on parent case (tasks remain per-case)
- Drag-and-drop reordering of sub-cases

## Decisions

### D1: Sub-case querying via OpenRegister filter

Query sub-cases by filtering on `parentCase` field using OpenRegister's search API: `GET /api/objects/{register}/{schema}?parentCase={parentUuid}`. This avoids maintaining a separate `subCases` array on the parent.

**Alternative considered**: Store a `subCases` array on the parent case object. Rejected because it creates a dual-write problem — both parent and child must be updated on creation/deletion, and OpenRegister's filter-by-property is sufficient.

### D2: Sub-case section as a component in CaseDetail.vue

Add a new `SubCasesSection.vue` component (following the pattern of `ParticipantsSection.vue`, `ResultSection.vue`, etc.) rendered in the case detail view. The component fetches sub-cases on mount and displays them in a compact table.

**Alternative considered**: Separate sub-cases tab. Rejected because sub-cases are a core part of the case context, not a secondary concern — they should be visible without tab switching.

### D3: Create Sub-case via CaseCreateDialog with parentCase context

Reuse the existing `CaseCreateDialog.vue` by passing a `parentCase` prop. When provided:
- The dialog title changes to "Create Sub-case"
- Case type dropdown is filtered to only show types in the parent case type's `subCaseTypes`
- The `parentCase` field is auto-set (hidden from user)
- The parent case breadcrumb is shown at the top of the dialog

### D4: Breadcrumb navigation via parentCase lookup

On CaseDetail.vue, if the loaded case has a `parentCase` UUID, fetch the parent case title and render a breadcrumb link above the case title: "Parent Case Title > Current Case Title". This is a single additional API call on case detail load.

### D5: Roll-up indicator as computed property

The sub-case progress indicator (e.g., "3/5 completed") is computed from the sub-cases query result. For case lists, a sub-case count is fetched as a separate lightweight query per visible case (or batch-loaded). For MVP, the case list shows a sub-case count badge only, not full roll-up.

## Risks / Trade-offs

- **[Performance] N+1 queries in case list** — Each case in the list needs a sub-case count query. Mitigation: batch-load sub-case counts for visible page using a single OpenRegister query with `parentCase` IN filter, or add a `subCaseCount` computed field on save.
- **[Data consistency] Orphaned sub-cases** — If a parent case is deleted, sub-cases retain a `parentCase` reference to a non-existent case. Mitigation: on parent case deletion, clear `parentCase` on all sub-cases (or block deletion if sub-cases exist).
- **[UX] Case type misconfiguration** — If a case type has no `subCaseTypes` configured, the "Create Sub-case" button should be hidden or disabled with a tooltip. Mitigation: check `subCaseTypes` array length before rendering the button.

## Open Questions

- Should closing a parent case warn if sub-cases are still open? (Recommendation: yes, show a warning but do not block)
