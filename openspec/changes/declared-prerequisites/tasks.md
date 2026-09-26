# Tasks: declared-prerequisites

Tier: V1. Kind: code. Row Q12.25.

- [x] 1.1 `lib/Prerequisites.php`: the declaration and `check()` (D-1, D-2).
  - `tests/Unit/PrerequisitesTest.php`: present and missing per kind, over an
    injected extension checker and an injected PHP version
  - `@spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md`
- [x] 1.2 The Prerequisites block: `src/views/settings/tabs/PrerequisitesTab.vue`,
  a section in `AdminRoot.vue`, fed by `prerequisites` initial state from
  `lib/Settings/AdminSettings.php`.
  - `tests/vitest/prerequisitesBlock.spec.js`
- [x] 1.3 `composer.json` gains `ext-json` and `ext-mbstring`, which 19 files
  under `lib/` already call and nothing required; the README table is
  regenerated between markers and the Nextcloud range corrected to what
  `info.xml` says; the drift test holds all three to the declaration (D-3).
- [x] 2.1 `tests/e2e/admin-prerequisites.spec.ts`; `openspec validate
  declared-prerequisites --strict`.
