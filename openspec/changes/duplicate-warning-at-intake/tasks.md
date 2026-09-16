# Tasks: duplicate-warning-at-intake

Tier: V1. Kind: config. Row 2.24.

- [x] 1.1 `lib/Settings/dossiq_register.json`: the three dedup rules on
  `case` (D-1); `caseType.duplicatePolicy`.
  - `@spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md`
- [x] 1.2 The create form's warning panel (D-2) and the policy behaviour
  (D-3); vitest over a stubbed dedup answer.
- [ ] 1.3 `PortalContributionProvider`: contribute the rules to the intake
  journey.
- [ ] 2.1 `tests/e2e/duplicate-warning.spec.ts`; `openspec validate
  duplicate-warning-at-intake --strict`.
