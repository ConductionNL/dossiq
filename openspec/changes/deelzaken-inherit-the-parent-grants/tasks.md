# Tasks: deelzaken-inherit-the-parent-grants

Tier: V1. Kind: code. Row Q13.23. Waits on openregister
`rbac-inherits-to-children` (open on openregister `development`).

- [x] 1.1 `lib/Settings/dossiq_register.json`: declare `parentCase` as the
  hierarchy edge on `case`, with a depth cap; `relatedCases` is not
  declared (D-1).
  - `@spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md`
- [x] 1.2 `lib/Service/CaseAccessGuard.php`: `hasCaseReadAccess()` and
  `hasCaseMutationAccess()` ask the platform; the app-side resolution is
  deleted in the same change, not left beside it (D-2).
  - `tests/Unit/Service/CaseAccessGuardTest.php`
- [x] 1.3 Pin the verb rule: a read grant on a parent does not become a
  mutation grant on a deelzaak (D-3).
- [x] 2.1 `src/manifest.json` `#CaseDetail`: a deelzaak opened through an
  inherited grant names the case that granted it (D-4).
  - `tests/vitest/caseAccessProvenance.spec.js`
- [x] 2.2 The Sharing tab of a case with deelzaken states that a share
  reaches them (D-5), in Dutch and English.
- [x] 3.1 `tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts`;
  `openspec validate deelzaken-inherit-the-parent-grants --strict`.
