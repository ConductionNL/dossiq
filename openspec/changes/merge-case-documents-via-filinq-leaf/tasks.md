# Tasks: merge-case-documents-via-filinq-leaf

- [x] 1.1 Place `filinq-merge-to-pdf` on `CaseDetail` as a grid panel with a layout cell, no `requiredApp` (dossiq#3185).
  - unit: `tests/vitest/siblingLeavesOnTheCase.spec.js` "places filinq-merge-to-pdf on the case page, in the grid"
- [x] 1.2 The panel title "Merge into one PDF" in en and nl.
- [ ] 2.1 Live check once filinq#1251 lands: on a case with three documents, the panel lists them, the handler orders two, and one PDF appears in the case folder.
