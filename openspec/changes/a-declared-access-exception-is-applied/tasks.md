# Tasks: a-declared-access-exception-is-applied

### Task 1: Apply a stored exception that is out of step
- **spec_ref**: `openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it`
- **files**: `lib/Service/Zaakdossier/DeclaredAccessExceptions.php`, `lib/Listener/DeclaredAccessExceptionListener.php`, `lib/AppInfo/Registrar/DocumentListenerRegistrar.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/Zaakdossier/DeclaredAccessExceptionsTest.php`, `tests/Unit/Listener/DeclaredAccessExceptionListenerTest.php`

### Task 2: The Zuiddrecht quote declares its exception
- **spec_ref**: `openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- [x] Implement
- [x] Test: `tests/Unit/Settings/ZuiddrechtParkwegDocumentsTest.php`
