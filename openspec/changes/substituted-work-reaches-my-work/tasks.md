# Tasks: substituted-work-reaches-my-work

Tier: V1. Kind: code. Row 13.17.

- [x] 1.1 `src/views/MyWorkCards.vue` and `src/views/widgets/MyWorkWidget.vue`:
  call `fetchSubstitutedWork()`, merge, mark, toggle (D-1).
  - vitest: rows merged and marked; toggle hides; every export of
    `substitutionHelpers.js` has a caller or is deleted
  - `@spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md`
- [x] 1.2 `lib/Service/SubstitutionService.php`: the leave read (D-2),
  bounded, guarded on humaniq's presence.
  - `tests/Unit/Service/SubstitutionServiceTest.php`: leave overrides,
    typed dates rule, humaniq absent
- [x] 2.1 `tests/e2e/spec-coverage/handler-vervanging-waarneming.spec.ts`:
  replace the line-70 exclusion with a test that asserts the marker, and
  fails without it (mutation: drop the fetch call, watch it red).
- [x] 2.2 `openspec validate substituted-work-reaches-my-work --strict`.
