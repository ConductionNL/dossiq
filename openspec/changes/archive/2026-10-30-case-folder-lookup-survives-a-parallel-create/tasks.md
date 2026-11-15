# Tasks: case-folder-lookup-survives-a-parallel-create

## 1. Folder lookup and the parallel create

- [x] 1.1 `folderOf()` retries a `LockedException` twice with a growing wait, then throws `RuntimeException('object_folder_busy')`
  - Spec ref: specs/document-projection/spec.md, "A case folder lookup MUST survive a parallel create"
  - Files: lib/Service/Zaakdossier/DocumentProjectionService.php
  - Test: tests/Unit/Service/Zaakdossier/CaseFolderLockTest.php, through the real `LinkedDocumentsController`, `LinkedDocumentsReader` and `DocumentProjectionService` (503 on a lock that stays, 200 when it lifts)
