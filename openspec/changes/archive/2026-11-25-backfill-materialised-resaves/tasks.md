# Tasks: backfill-materialised-resaves

- [x] 1. Test first: BackfillCaseTypeIdentifierTest's store refuses a changed readOnly value and materialises `@ref` on save; it fails on development (2 failed).
- [x] 2. BackfillReferencedCaseValue re-saves the case with an empty patch instead of supplying the readOnly value.
- [x] 3. BackfillCaseStatusRoleTest asserts the empty patch.
- [x] 4. Coordinator: after merge and deploy, `occ maintenance:repair` on :8080 reports 0 failed for both backfills.
  - Live 25 Nov 2026 on a sandbox instead of :8080 (Nextcloud 34.0.6 + MariaDB, OpenRegister main 987938d4, dossiq development, 48 cases from the Zuiddrecht set): first run `statusRole backfill: 0 filled, 0 written but not confirmed, 0 failed, 48 already right` and `caseTypeIdentifier backfill: 0 filled, ... 0 failed, 48 already right`. Then `case_type_identifier` and `status_role` were blanked in the case table on three cases and the repair ran again: `statusRole backfill: 3 filled, 0 written but not confirmed, 0 failed`; `caseTypeIdentifier backfill: 0 filled, 0 failed, 48 already right`, because the statusRole step's empty-patch re-save (which runs first) had already re-materialised `caseTypeIdentifier` on the same three cases (the column counts went from 5/7 null back to 2/4, the cases the set ships without either). No readOnly refusal.
