# Tasks: split-picker-asks-the-policy

Tier: V1. Kind: code. Size S. Follow-up to #2945 and #2957.

- [x] 1.1 `CaseSplitExecutor::divisibleParts()` and
  `GET /api/case/{caseId}/split` (D-1).
  - `@spec openspec/changes/split-picker-asks-the-policy/specs/case-management/spec.md`
  - `tests/Unit/Service/Cases/CaseSplitDivisiblePartsTest.php`
- [x] 2.1 The dialog asks before it draws, reads only allowed parts, and tells
  the two empty states apart (D-2, D-3, D-4).
  - `tests/vitest/caseSplitPicker.spec.js`
