# Tasks: cases-views-are-places

Tier: V1. Kind: config. Row Q9.16. Waits on nextcloud-vue
`saved-view-as-a-place` (open on nextcloud-vue `development`).

- [ ] 1.1 `src/manifest.json`: declare saved-view places on `#Cases`,
  `#Queue` and `#Tasks`, with the pinned views hanging under each page's
  own navigation entry (D-1).
  - `tests/vitest/caseListLenses.spec.js`
  - `@spec openspec/changes/cases-views-are-places/specs/case-management/spec.md`
- [ ] 1.2 Give the seeded views a presentation: Overdue as a list, the
  desk view as a board (D-2).
- [ ] 1.3 Seed no pinned view, so the navigation on a fresh install is
  unchanged (D-3).
- [ ] 2.1 `tests/e2e/cases-views-are-places.spec.ts`: open a view by its
  own URL, switch presentation, pin it, find it in the navigation;
  `openspec validate cases-views-are-places --strict`.
