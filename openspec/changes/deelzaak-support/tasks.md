## 1. Store Layer — Sub-case Queries (V1)

- [x] 1.1 Add `fetchSubCases(parentCaseUuid)` action to the case store that queries OpenRegister with `parentCase` filter
- [x] 1.2 Add `fetchParentCase(parentCaseUuid)` action to the case store for breadcrumb data
- [x] 1.3 Add `subCases` and `parentCase` state properties to the case store with appropriate getters

## 2. Sub-cases Section Component (V1)

- [x] 2.1 Create `src/views/cases/components/SubCasesSection.vue` component displaying sub-cases in a compact table (title, status, assignee, deadline columns)
- [x] 2.2 Add sub-case progress roll-up indicator in the section header ("Sub-cases (X/Y completed)")
- [x] 2.3 Add empty state message "No sub-cases yet" when case type has `subCaseTypes` but no sub-cases exist
- [x] 2.4 Integrate `SubCasesSection` into `CaseDetail.vue` — render only when case type has `subCaseTypes` configured

## 3. Sub-case Creation (V1)

- [x] 3.1 Extend `CaseCreateDialog.vue` to accept optional `parentCase` prop (UUID) and `parentCaseType` prop
- [x] 3.2 When `parentCase` is provided: change dialog title to "Create Sub-case", filter case type dropdown to `subCaseTypes`, auto-set `parentCase` field on submit
- [x] 3.3 Add "Create Sub-case" button in `SubCasesSection.vue` that opens `CaseCreateDialog` with parent context
- [x] 3.4 Hide "Create Sub-case" button when: parent case is closed (`endDate` set), current case is itself a sub-case (`parentCase` is non-null), or case type has empty `subCaseTypes`

## 4. Parent Case Breadcrumb (V1)

- [x] 4.1 Add breadcrumb rendering in `CaseDetail.vue` — when loaded case has `parentCase`, fetch parent case title and render clickable breadcrumb above the case title
- [x] 4.2 Ensure breadcrumb is not rendered for top-level cases (null `parentCase`)

## 5. Case List Sub-case Count (V1)

- [x] 5.1 Add sub-case count badge to `CaseList.vue` — for each case in the list, show a badge if it has sub-cases (count > 0)
- [x] 5.2 Batch-load sub-case counts for the visible page to avoid N+1 queries

## 6. Deletion Protection (V1)

- [x] 6.1 When deleting a case that has sub-cases, show a confirmation dialog warning about sub-case detachment
- [x] 6.2 On confirmed deletion, clear `parentCase` field on all child cases before deleting the parent

## 7. Verification (V1)

- [x] 7.1 Verify sub-case creation sets `parentCase` correctly in OpenRegister
- [x] 7.2 Verify sub-case type constraint — only `subCaseTypes` from parent case type are offered
- [x] 7.3 Verify nesting prevention — "Create Sub-case" button is hidden on sub-cases
- [x] 7.4 Verify breadcrumb navigation links correctly to parent case
- [x] 7.5 Verify roll-up indicator shows correct completed/total counts
- [x] 7.6 Verify case list badge shows correct sub-case counts
- [x] 7.7 Verify deletion protection dialog appears and orphan cleanup works
