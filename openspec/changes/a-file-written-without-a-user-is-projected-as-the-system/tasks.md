# Tasks: a-file-written-without-a-user-is-projected-as-the-system

- [x] 1.1 `CaseFolderNodeListener` runs create, write, delete and rename projections as the system when no user is signed in
  - Files: lib/Listener/CaseFolderNodeListener.php
- [x] 1.2 Tests: no user projects as the system, a signed-in user is never elevated, a file outside the tree is never elevated
  - Test: tests/Unit/Listener/CaseFolderNodeListenerTest.php
- [ ] 1.3 Live: install an example set from the command line and see no projection error and an informatieobject per file
