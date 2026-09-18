# Tasks: knowledge-base-on-the-case

Tier: V1. Kind: config. Row 11.26.

- [ ] 1.1 Add `collectives` and `xwiki` to `case.linkedTypes` in
  `lib/Settings/dossiq_register.json`.
  - `@spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md`
- [ ] 1.2 `caseType.knowledgeBasePage`: a reference to the collective page
  that is the work instruction for the type, optional.
- [ ] 2.1 `src/registry.js`: a `KnowledgeLeafTab` through `leafTab('collectives')`
  with `requiredApp: 'collectives'`, so the tab is absent rather than broken
  on an instance without it.
- [ ] 2.2 `src/manifest.json` `#CaseDetail`: the Knowledge tab, read only,
  showing the case type's `knowledgeBasePage` first and the case's own linked
  pages under it.
- [ ] 2.3 `#CaseTypeDetail`: a field to pick the work instruction page.
- [ ] 3.1 Confirm on a running instance that a user outside the collective's
  team sees no page rather than an empty page with a title, and record which
  of the two the leaf does.
- [ ] 4.1 `tests/vitest/`: the tab declares `requiredApp`, and dossiq stores
  no article text of its own.
- [ ] 4.2 `tests/e2e/knowledge-base-on-the-case.spec.ts`: a case type with a
  work instruction shows it on every case of that type.
