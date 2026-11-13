# Tasks

## 1. Remove the note push

- [x] 1.1 Remove the `noteActions` entry `push-to-neighbouring-register` from the Notes tab in `src/manifest.json` #CaseDetail, and drop the sentence about it from that widget's `_note`.
- [x] 1.2 Remove the `notes#push` route from `appinfo/routes.php` and the push action from `lib/Controller/NotesController.php`; keep `notes#mention`.
- [x] 1.3 Delete `lib/Service/Notes/NotePush.php` and `lib/Service/Notes/NoteEnvelope.php`, and any registration or reserved note type that only they read.
- [x] 1.4 Delete `tests/Unit/Service/Notes/NotePushTest.php`, `tests/Unit/Service/Notes/NoteEnvelopeTest.php` and `tests/e2e/note-sync.spec.ts`; remove the push cases from `tests/Unit/Controller/NotesControllerTest.php` and the push assertions from the vitest specs that name the action.

## 2. Hand-over

- [x] 2.1 No `@spec` tag still points at the removed requirements (`grep -rn "neighbouring register" lib src tests`).
