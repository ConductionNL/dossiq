# Tasks: case-page-and-list-as-a-place

Tier: V1. Kind: config. Size M. Round 4 discovery cluster 58, candidates
C-search-30, C-search-25, C-search-4, C-search-19, C-configuration-55 and
C-configuration-27. No decision. Waits on nextcloud-vue, cluster 58, to be
specified there as `saved-view-as-a-place` extended. No PHP.

- [ ] 1.1 `src/manifest.json`: `#Cases` and `#Queue` declare a case
  opening beside the list, with the list keeping its position and its
  selection (D-1, D-2, D-3).
  - `tests/vitest/caseListPlace.spec.js`
  - `@spec openspec/changes/case-page-and-list-as-a-place/specs/case-management/spec.md`
- [ ] 1.2 Declare next and previous within the list, carrying the applied
  filters and ordering (D-4).
- [ ] 1.3 `#CaseDetail` declares the reference summary in place,
  disclosing only what the reader may see (D-2).
- [ ] 1.4 Declare nothing on the pages nobody triages from, and add a test
  that asserts it (D-2).
- [ ] 2.1 `src/personalSettings.js`: dossiq's per-person options, and no
  second screen (D-5).
  - `tests/vitest/personalSettings.spec.js`
- [ ] 2.2 `#Cases` declares a per-person held order, off by default (D-6).
- [ ] 3.1 Hand nextcloud-vue the six candidate ids this change declares
  against, with the cluster and the lane citations, so its half is scoped
  to what dossiq asked for.
- [ ] 3.2 Record the nine candidates this change does not carry, with
  their single driven passer each, so nobody rediscovers them.
- [ ] 3.3 `tests/e2e/case-page-and-list-as-a-place.spec.ts`: scroll a
  queue, open a case beside it, step to the next inside a filter, read a
  reference summary, hold a personal order and confirm a colleague does
  not see it; `openspec validate case-page-and-list-as-a-place --strict`.
