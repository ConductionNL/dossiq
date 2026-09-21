# Tasks: deelzaken-inherit-the-parent-grants

Tier: V1. Kind: code. Row Q13.23. Waits on openregister
`rbac-inherits-to-children` (open on openregister `development`).

- [x] 1.1 `lib/Settings/dossiq_register.json`: declare `parentCase` as the
  hierarchy edge on `case`, with a depth cap; `relatedCases` is not
  declared (D-1).
  - `@spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md`
- [x] 1.2 `lib/Service/CaseAccessGuard.php`: the app-side resolution is
  DELETED (D-2). `readAccessSource()`, `worksOnCase()` and
  `HIERARCHY_MAX_DEPTH` are gone, and `hasCaseReadAccess()` asks
  OpenRegister's `ObjectGrantResolver` instead, which since openregister#3873
  expands a grant over the declared `x-openregister-hierarchy` edge.
  **`hasCaseMutationAccess()` deliberately does NOT ask it**: that is where
  the verb would widen, now across an app boundary.
  **It does not defer wholesale to the case resolving**, either:
  `ObjectService::find()` applies the SCHEMA's read rule, so on an instance
  whose case schema admits `authenticated` a wholesale deferral would make
  this guard one that every logged-in user passes, with nothing looking
  different afterwards.
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
