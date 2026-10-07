# Tasks: case-claim-action

Tier: V1. Kind: code (was config: the rule that refuses a second claim cannot
live in a manifest, see design D-1). Row 2.4.

- [x] 1.1 `lib/Service/CaseAssignmentService.php` + `CaseAssignmentController`:
  read-compare-write over the stored case, `claim` refused when the case has a
  handler, `release` refused when the caller is not that handler; three routes
  in `appinfo/routes.php`. The browser-side object-store handlers the task
  named cannot refuse a second claim, and a `handler` header action cannot
  resolve a function at all (design D-4).
  - PHPUnit: `tests/Unit/Controller/CaseAssignmentControllerTest.php`, 11 tests
    over the pass and every refusal, each asserting the STATUS
  - `@spec openspec/changes/case-claim-action/specs/case-management/spec.md`
- [x] 1.2 `src/manifest.json` `#CaseDetail` header actions `case-claim` and
  `case-release`, both `api-call`, gated on the case being open. The
  `visibleIf` the task named is not expressible (design D-2).
  - `tests/vitest/caseClaimAction.spec.js`: both present, with their type,
    url, route, icon and gate
- [x] 1.3 `#Queue` and `#Cases` row action Claim, a function handler in
  `src/utils/caseClaim.js` registered in `src/customComponents.js`. Per-row
  visibility is not expressible either (design D-4).
  - `tests/vitest/caseClaimAction.spec.js`: the row action resolves to a
    function that is exported, posts the row's case, and shows the server's
    refusal unchanged
- [x] 2.1 `tests/e2e/case-claim.spec.ts`, citing all three scenarios;
  `openspec validate case-claim-action --strict`.
