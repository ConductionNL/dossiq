# Tasks: document-lookups-stay-in-their-schema

### Task 1: Lookups stay in their schema
- **spec_ref**: `openspec/changes/document-lookups-stay-in-their-schema/specs/document-projection/spec.md#requirement-a-document-lookup-answers-only-its-own-schema`
- **files**: `lib/Service/Zaakdossier/DocumentRecordStore.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/Zaakdossier/DocumentRecordStoreTest.php`

### Task 2: One record per file on existing instances
- **spec_ref**: `openspec/changes/document-lookups-stay-in-their-schema/specs/document-projection/spec.md#requirement-existing-second-records-of-a-file-are-removed`
- **files**: `lib/Repair/RemoveDocumentRecordTwins.php`, `appinfo/info.xml`
- [x] Implement
- [x] Test: `tests/Unit/Repair/RemoveDocumentRecordTwinsTest.php`
