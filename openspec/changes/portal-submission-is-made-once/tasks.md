# Tasks: portal-submission-is-made-once

- [x] 1.1 `lib/Portal/PortalSubmissionLedger.php`: claim (atomic `add()` on an `IMemcache`), remember, release; entries per action scope, resident and key. Verify: `tests/Unit/Controller/PortalWooRequestSubmissionKeyTest.php`.
- [x] 1.2 `PortalWooRequestController::start()` replays an answered key (201), answers 409 `submission_processing` for a busy one, lets a refused one go. Verify: `PortalWooRequestSubmissionKeyTest` (4 tests; the replay test fails with the replay removed).
- [x] 2.1 `lib/BackgroundJob/LinkCollectionToCaseJob.php` and `WooRequestIntake::linkLater()`: the dossier link is queued after the case is written. Verify: `WooRequestIntakeTest::testWithAJobListTheDossierIsLinkedAfterTheCaseIsAnswered`; the inline tests stay green.
- [ ] 3.1 Live (decision 139): on :8080 with portaliq's `action-forward-survives-a-slow-answer`, start a Woo request from a dossier, time the answer, and send it twice with the same key: one case, and the dossier's documents on it after cron.
