# Tasks: contacts-secrecy-pill

- [x] 1.1 Test: the BSN column declares the pill (`tests/vitest/contactsSecrecyPill.spec.js`, red before).
- [x] 1.2 Config.
- [ ] 1.3 Live: a protected resident shows "Geheimhouding" beside the BSN on :8080.
  - 25 Nov 2026 live: the pill renders for Jan de Cuykelaer (`cn-cell-pill` "Geheimhouding" / "Data protection"), but the BSN column's 211px (from `contacts-list-columns-as-drawn`) clips it: the cell needs 228px in Dutch, 225px in English. Tick once that column fits the pill.
