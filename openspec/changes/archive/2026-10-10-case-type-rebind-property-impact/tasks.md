# Tasks: case-type-rebind-property-impact

Kind: code. Extends `case-type-rebind`.

- [x] 1.1 `lib/Service/Cases/RebindValueConverter.php`: whether a stored
  answer fits a target field, and its value there.
- [x] 1.2 `lib/Service/Cases/CaseRebindImpact.php`: dropped, ported,
  required; remap validation; apply onto the case's `properties` list.
- [x] 1.3 `lib/Service/Cases/CaseAnswerReader.php` reads the list shape
  (and a legacy map). `CaseRebindGate` loses its map-shaped `answersOf`,
  `applyAnswers` and `missingAt` and gains `assertDropConfirmed`.
- [x] 1.4 `CaseRebindService::preview()` returns `impact`;
  `rebind()` applies it, refuses an unconfirmed drop, journals the drop.
- [x] 1.5 `CaseRebindController` passes `remap`, `properties` and
  `confirmDropped`.
- [x] 1.6 `tests/Unit/Service/Cases/CaseRebindImpactTest.php` and
  `tests/Unit/Service/CaseRebindServiceTest.php` on the register's list
  shape.
- [x] 2.1 `src/components/case/CaseRebindImpact.vue` and
  `RebindPropertyField.vue`; `CaseRebindDialog` re-previews on every
  remap and answer, and blocks confirm until the server says complete.
- [x] 2.2 `tests/vitest/caseRebindImpact.spec.js`.
- [x] 2.3 Strings in `l10n/en.json` and `l10n/nl.json` (and the built
  `l10n/en.js`, `l10n/nl.js`).
- [ ] 3.1 `tests/e2e/case-type-rebind.spec.ts` asserts the list shape of the
  written answer. Written, not run in this lane (no instance assigned).
  (live pass, decision 139)
