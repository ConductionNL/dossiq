# Tasks: case-grants-name-their-source

Tier: V1. Kind: code. Size M. Round 4 discovery clusters 11 and 54,
candidates C-access-and-privacy-45 (matrix hole),
C-access-and-privacy-62 (matrix hole), C-access-and-privacy-46 and
C-access-and-privacy-47; C-access-and-privacy-64 and
C-access-and-privacy-79 already pass and are protected here rather than
built. Decision D22. Waits on openregister
`permission-provenance-and-deny` and `rbac-inherits-to-children`, both
open on openregister `development`.

- [ ] 1.1 The case access panel: who holds which right and where each
  grant came from, read from openregister, stored nowhere (D-1, D-5).
  - `tests/vitest/caseAccessPanel.spec.js`
  - `@spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md`
- [ ] 1.2 A test that fails when dossiq gains an effective-permission
  evaluator of its own (D-1).
- [ ] 2.1 `lib/Lifecycle/CaseActionProvider.php`: read openregister's
  effective grants beside its own guards, and carry the refusing rule into
  the refusal (D-2).
  - `tests/unit/Lifecycle/CaseActionProviderTest.php`
- [ ] 3.1 `caseType` and `register.d/61-mandaat-matrix.json`: department by
  role by confidentiality, with confidentiality as an axis and not a role
  name (D-3).
  - `tests/unit/Service/MandaatMatrixTest.php`
- [ ] 3.2 Case-type groups, with the grant on the group and inheritance
  left to openregister (D-4).
- [ ] 4.1 Remind the openregister lane, in the hand-off, of D22's own
  instruction: say in `permission-provenance-and-deny` that the condition
  is compiled into the query and not checked on the result, because the
  two are the same sentence in English and different products in practice.
- [ ] 4.2 `tests/e2e/case-grants-name-their-source.spec.ts`: read the
  access panel, be refused with a named rule, list cases as a department
  without the confidential ones, and grant a group once;
  `openspec validate case-grants-name-their-source --strict`.
