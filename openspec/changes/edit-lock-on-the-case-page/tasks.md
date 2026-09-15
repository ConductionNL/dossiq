# Tasks: edit-lock-on-the-case-page

Tier: V1. Kind: config. Row 2.27. Waits on openregister
`run-scoped-object-locking` landing on `development`.

- [ ] 1.1 Edit form: take on open, release on close and on route leave
  (D-1); vitest for both.
  - `@spec openspec/changes/edit-lock-on-the-case-page/specs/case-management/spec.md`
- [ ] 1.2 Header widget: holder and time from `locked`; Edit `visibleIf`
  (D-2).
- [ ] 1.3 Refused write: show the message, keep input (D-3).
- [ ] 2.1 `tests/e2e/case-edit-lock.spec.ts` with two browser contexts;
  `openspec validate edit-lock-on-the-case-page --strict`.
