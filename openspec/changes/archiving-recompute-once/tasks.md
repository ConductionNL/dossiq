## 1. Config

- [x] 1.1 Drop the `headerActions` recompute on `case-archival-panel` in `src/menu-layout.simple.json`; carry the subtitle in `content` (test: `tests/vitest/recordCardHeaderActions.spec.js`, red on development).
- [ ] 1.2 Live check on :8080 after merge: one "Nominatie herberekenen" on the Archiving tab (COMPDIFF DqZaakArchivering).
- [x] 1.3 Before the case closes the Archiving card shows the outcome the case type's result types agree on, the procedure hold and the e-Depot transfer (nextcloud-vue feat/retention-expected-outcome; test `tests/vitest/recordCardHeaderActions.spec.js`)
- [x] 1.4 The Woo result types name their VNG Selectielijst 2020 row ("Verzoeken behandelen": 6.1 Ingewilligd, 6.2 Afgewezen, 6.3 Afgebroken) by URL; the card reads it through OpenRegister's selectielijst resolve (openregister feat/selectielijst-resolve); tests WooResultTypesSelectionListTest, recordCardHeaderActions.spec.js
