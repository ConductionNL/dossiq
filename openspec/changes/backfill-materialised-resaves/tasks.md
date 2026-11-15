# Tasks: backfill-materialised-resaves

- [x] 1. Test first: BackfillCaseTypeIdentifierTest's store refuses a changed readOnly value and materialises `@ref` on save; it fails on development (2 failed).
- [x] 2. BackfillReferencedCaseValue re-saves the case with an empty patch instead of supplying the readOnly value.
- [x] 3. BackfillCaseStatusRoleTest asserts the empty patch.
- [ ] 4. Coordinator: after merge and deploy, `occ maintenance:repair` on :8080 reports 0 failed for both backfills.
