# Tasks: woo-screens-follow-the-case

- [x] 1.1 Delivered sets: dates in the reader's locale, states in words.
  - `tests/vitest/manifestWooDeliveredSets.spec.js` (control: fails on the old manifest)
- [x] 1.2 The assessment dialog drops a saved document at once.
  - `tests/vitest/documentAssessDialog.spec.js` "drops a saved document at once, even when the next read still lists it" (control: fails on the old dialog)
- [x] 1.3 Labels in the assessment dialog break between words (CSS; no layout test can see it, checked by eye in the next rehearsal).
- [x] 1.4 One wording for an optional field in Dutch.
  - `tests/vitest/optionalFieldWording.spec.js` (control: fails on the old catalogue)
- [x] 1.5 Publicatie carries the decision's role.
  - `tests/Unit/Settings/WooStatusRolesTest.php` (control: fails on the old fragment)
- [x] 1.6 A term written with nobody signed in is written as the system.
  - `tests/Unit/Service/Termijn/TermInstanceStoreSystemWriteTest.php`, with a control that a signed-in user's write is not elevated
