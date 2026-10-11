# Tasks: translatable-titles-read-as-text

- [x] 1.1 Route every title and name cast of a case type, status, result or
  role type through `LanguageMapText` (41 sites, 30 files).
  - `tests/Unit/Listener/CaseTypePortalWithdrawalListenerTest.php::testStatusNamesStoredAsLanguageMapsAreNamedInTheRefusal`
- [x] 1.2 `LanguageMapText::textOr()` for casts that named a fallback.
- [x] 2.1 The workflow backfill names its template and its steps and moves in
  text, without an em-dash, and heals the ones it stored as "Array".
  - `tests/Unit/Repair/MigrateWorkflowDefinitionsLabelsTest.php`
- [ ] 2.2 Operator: after the upgrade, run `occ dossiq:workflows:migrate-to-flows`
  so the flows made from healed templates take the new names.
