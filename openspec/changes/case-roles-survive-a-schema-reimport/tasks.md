# Tasks: case-roles-survive-a-schema-reimport

### Task 1: Re-sync on a case schema update
- **spec_ref**: `openspec/changes/case-roles-survive-a-schema-reimport/specs/people-on-the-case/spec.md#requirement-the-case-schemas-role-vocabulary-survives-a-rewrite-of-the-schema`
- **files**: `lib/Listener/CaseSchemaVocabularyListener.php`, `lib/AppInfo/Registrar/PersonListenerRegistrar.php`, `tests/Stubs/Event/SchemaUpdatedEvent.php`
- [x] Implement
- [x] Test: `tests/Unit/Listener/CaseSchemaVocabularyListenerTest.php`, `tests/Unit/AppInfo/PersonListenerRegistrarTest.php`
