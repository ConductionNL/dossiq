# Tasks: case-merge

Tier: V1. Kind: config plus one listener. Row 2.23.

- [x] 1.1 `lib/Settings/dossiq_register.json` `case`: the `x-openregister-merge`
  declaration (D-1), `mergedInto`, result type `merged`.
  - `@spec openspec/changes/case-merge/specs/case-management/spec.md`
- [x] 1.2 `src/manifest.json` `#CaseDetail` header action `case-merge`
  (survivor picker, confirm), refused for final or signed cases (D-4).
- [x] 1.3 `lib/Listener/CaseMergedListener.php` on the platform's merge and
  reversal events, deferred (D-2).
  - unit: term completed on merge, re-armed on reversal
- [x] 2.1 `email-case-matching` resolves `mergedInto`; `PublicStatusPage`
  too (D-3).
- [x] 3.1 `tests/e2e/case-merge.spec.ts`; `openspec validate case-merge
  --strict`.
