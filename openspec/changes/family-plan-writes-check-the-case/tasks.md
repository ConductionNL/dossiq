# Tasks: family-plan-writes-check-the-case

- [x] 1.1 Tests red first: `tests/Unit/Controller/SociaalDomeinPlanWriteAccessTest.php` (10; the controller took no guard).
- [x] 1.2 `CaseAccessGuard::hasCaseMutationAccess()` before every plan write; 401 / 403 / 404 write nothing.
- [x] 1.3 Existing contract and plan-of-case tests pass the guard.
