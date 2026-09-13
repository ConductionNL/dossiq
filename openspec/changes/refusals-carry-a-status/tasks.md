# Tasks: refusals-carry-a-status

Tier: V1. Kind: code. Row Q10.14. Ordered so the instrument exists before
the triage.

- [ ] 1.1 `tests/Unit/Architecture/ServiceCatchReturnsNullTest.php` and
  `catch-return-null.allowlist.json` seeded with the 47 measured sites,
  each with class and reason (D-1). Fixture test for a new site and for
  the ceiling.
  - `@spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md`
- [ ] 1.2 Triage: classify the 47 into refusal, degradation, read-miss;
  record the counts here.
- [ ] 2.1 Convert the `refusal` sites in batches of no more than ten per
  PR: typed exception, controller translation (ADR-105), entry removed;
  one mutation per new status assertion recorded in the PR body.
- [ ] 2.2 `lib/Exception/RefusedException.php` (rule slug, message) and
  its 409 mapping, where no tracked class fits.
- [ ] 3.1 The 13 controller suites without `getStatus()`: add the
  assertion on both branches; prove each with a mutation.
- [ ] 4.1 `tests/e2e/refusal-status.spec.ts`; `openspec validate
  refusals-carry-a-status --strict`.
