## 1. Config

- [x] 1.1 Drop the `headerActions` recompute on `case-archival-panel` in `src/menu-layout.simple.json`; carry the subtitle in `content` (test: `tests/vitest/recordCardHeaderActions.spec.js`, red on development).
- [ ] 1.2 Live check on :8080 after merge: one "Nominatie herberekenen" on the Archiving tab (COMPDIFF DqZaakArchivering).
- [x] 1.3 Before the case closes the Archiving card shows the outcome the case type's result types agree on, the procedure hold and the e-Depot transfer (nextcloud-vue feat/retention-expected-outcome; test `tests/vitest/recordCardHeaderActions.spec.js`)
