# Case notes stay in dossiq

## Why

Decision 395(b), 12 Nov 2026: case notes stay in dossiq and are stored in OpenRegister as notes on the case. There is no sync to an external case register.

`zgw-api-mapping` still requires the opposite. Three requirements push a note to the neighbouring ZGW register as a document, keep an internal note here, and record the outcome of each push on the case. They came from the archived change `a-case-note-reaches-the-neighbouring-register` and the code behind them still ships.

Row `6.14` already says what Ruben decided and links OpenRegister's notes requirement (`openregister:openspec/specs/object-interactions :: Notes on Objects via ICommentsManager`). This change brings the spec and the code in line with that row.

## What changes

- `zgw-api-mapping` loses the three note push requirements.
- The Notes tab of the case loses its "Send to the neighbouring register" action.
- The push endpoint and the services behind it are removed, with their tests.
- A note is written, read and mentioned exactly as before: OpenRegister stores it as a comment on the case, and dossiq's mention endpoint stays.

## Impact

- Spec: `zgw-api-mapping` (REMOVED).
- Code: `appinfo/routes.php` (`notes#push`), `lib/Controller/NotesController.php` (the push action only), `lib/Service/Notes/NotePush.php`, `lib/Service/Notes/NoteEnvelope.php`, the `noteActions` entry of `src/manifest.json` #CaseDetail Notes tab.
- Tests: `tests/Unit/Service/Notes/NotePushTest.php`, `tests/Unit/Service/Notes/NoteEnvelopeTest.php`, the push cases in `tests/Unit/Controller/NotesControllerTest.php`, `tests/e2e/note-sync.spec.ts`, and the push assertions in the vitest specs that name the action.
- Row `6.14` keeps its state: notes on the case are built in OpenRegister.
