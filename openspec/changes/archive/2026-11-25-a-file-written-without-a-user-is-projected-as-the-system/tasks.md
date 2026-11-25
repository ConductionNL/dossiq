# Tasks: a-file-written-without-a-user-is-projected-as-the-system

- [x] 1.1 `CaseFolderNodeListener` runs create, write, delete and rename projections as the system when no user is signed in
  - Files: lib/Listener/CaseFolderNodeListener.php, lib/Service/Zaakdossier/DocumentProjectionService.php (`asSystem()`)
- [x] 1.2 Tests: no user projects as the system, a signed-in user is never elevated, a file outside the tree is never elevated
  - Test: tests/Unit/Listener/CaseFolderNodeListenerTest.php, tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php
- [x] 1.3 Live: install an example set from the command line and see no projection error and an informatieobject per file
  - Done 25 Nov 2026 on a sandbox instance (NC 34.0.6, MariaDB): `occ openregister:example-set:install zuiddrecht` logged no projection error; each of the 15 files in dossiq case folders has its `informatieobject` (same uuid as the folder, `file_name` set).
