# Tasks: translatable-titles-read-as-text

- [x] 1.1 Route every title and name cast of a case type, status, result or
  role type through `LanguageMapText` (41 sites, 30 files).
  - `tests/Unit/Listener/CaseTypePortalWithdrawalListenerTest.php::testStatusNamesStoredAsLanguageMapsAreNamedInTheRefusal`
  - Round 2: five sites added since then still cast (`CaseRelationDetails`, `EndOfDayRowMeta`, `CaseTypeRuleProjector`, `PenaltyPaymentRows`, `HouseholdCases`); each now reads through `LanguageMapText`, with a language-map test beside its existing tests.
- [x] 1.2 `LanguageMapText::textOr()` for casts that named a fallback.
- [x] 2.1 The workflow backfill names its template and its steps and moves in
  text, without an em-dash, and heals the ones it stored as "Array".
  - `tests/Unit/Repair/MigrateWorkflowDefinitionsLabelsTest.php`
- [x] 2.2 Operator: after the upgrade, run `occ dossiq:workflows:migrate-to-flows`
  so the flows made from healed templates take the new names.
  - Live 25 Nov 2026 (sandbox Nextcloud 34.0.6 + MariaDB, OpenRegister main 987938d4, dossiq development, after `occ maintenance:repair`): `occ dossiq:workflows:migrate-to-flows --user admin` gave `total 37, created 37, updated 0, skipped 0, failed 0`. None of the 37 flows has "Array" or an em-dash in its name (`Bijstandsaanvraag`, `Melding Jeugdwet`, `Omgevingsvergunning Bouwactiviteit (regulier)`, ...), and a second run updated the same 37 (no duplicates). On this fresh install the repair had no "Array" template to heal; the healing itself is covered by `MigrateWorkflowDefinitionsLabelsTest`.
  - Archived with the requirement renumbered REQ-CT-46 -> REQ-CT-58: the main spec already uses REQ-CT-46 for "A procedure ships as a package on disk".
