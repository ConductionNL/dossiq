# Tasks: live-updates-on-the-case-page

Tier: V1. Kind: code. Row 2.20.

- [ ] 1.1 Case store: `liveUpdatesPlugin` for `@objectId` and its runs
  (D-1, D-2); vitest that an event triggers one fetch.
  - `@spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md`
- [ ] 1.2 `src/manifest.json` `#CaseDetail` `case-flow-runs`: drop
  `pollSeconds`; vitest asserts none on the page (D-3).
- [ ] 2.1 `tests/e2e/case-detail-kpis-and-tabs.spec.ts`: the two-session
  scenario; `openspec validate live-updates-on-the-case-page --strict`.
