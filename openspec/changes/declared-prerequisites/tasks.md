# Tasks: declared-prerequisites

Tier: V1. Kind: code. Row Q12.25.

- [ ] 1.1 `lib/Prerequisites.php`: `DECLARED` and `check()` (D-1, D-2).
  - `tests/Unit/PrerequisitesTest.php`: present and missing per kind
  - `@spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md`
- [ ] 1.2 Admin settings section: the Prerequisites block.
- [ ] 1.3 README Requirements table from the declaration (28 to 34 becomes
  what `info.xml` says); the drift test (D-3).
- [ ] 2.1 `tests/e2e/admin-prerequisites.spec.ts`; `openspec validate
  declared-prerequisites --strict`.
