# Tasks: knowledge-base-on-the-case

Tier: V1. Kind: config. Row 11.26.

- [x] 1.1 Add `collectives` and `xwiki` to `case.linkedTypes` in
  `lib/Settings/dossiq_register.json`.
  - `@spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md`
- [x] 1.2 `caseType.knowledgeBasePage`: a reference to the collective page
  that is the work instruction for the type, optional.
  - In `lib/Settings/register.d/41-case-knowledge-base.json` per ADR-037, not
    in `dossiq_register.json`, because several dossiq lanes build against that
    file at once. `linkedTypes` had to be edited in place there: it is an
    existing array inside the case schema's `configuration` block, and a
    fragment adding a `configuration` block is taken whole by one side.
  - No `format`. Adding a format to a property OpenRegister already stores is
    breaking for every row that does not match it, and a municipality will
    paste a page path rather than a full URL.
- [x] 2.1 `src/registry.js`: a `KnowledgeLeafTab` through `leafTab('collectives')`
  with `requiredApp: 'collectives'`, so the tab is absent rather than broken
  on an instance without it.
  - PLACED AS A BUILT-IN `integration` WIDGET INSTEAD, and no registry key was
    added. This page already consumes leaves that way (`case-files`,
    `case-kpis-hours`, `case-payment-requests`); `leafTab()` exists for
    `component:` SIDEBAR tabs, which this is not. The requirement is met more
    strongly than by a restatement: the library's own descriptor carries
    `requiredApp: 'collectives'`, and a leaf whose app is absent is never
    registered, so the tab goes missing rather than drawing an empty knowledge
    base. A second way to place one leaf on one page is how two placements
    drift.
  - Verified against the INSTALLED library (3.2.0): both
    `src/integrations/builtin/collectives.js` and its `xwiki` sibling ship,
    and collectives declares `requiredApp: 'collectives'`. Unlike the other
    two changes in this lane, nothing here waits on an unpublished release.
- [x] 2.2 `src/manifest.json` `#CaseDetail`: the Knowledge tab, read only,
  showing the case type's `knowledgeBasePage` first and the case's own linked
  pages under it.
  - The tab renders the case's own linked pages. THE CASE TYPE'S INSTRUCTION
    IS NOT ON THE CASE PAGE YET, and that is the one part of REQ-CKB-01 this
    change does not close. Measured: `CnDetailPage` reads no `extend`, so a
    dotted path over the case's `caseType` reference resolves to nothing, and
    a widget declared that way would render blank while looking configured,
    which is the failure this whole change is written against. Putting a case
    type's property on a case page needs either an expansion the detail page
    does not do or a component, and either is a change of its own rather than
    a declaration.
- [x] 2.3 `#CaseTypeDetail`: a field to pick the work instruction page.
- [ ] 3.1 Confirm on a running instance that a user outside the collective's
  team sees no page rather than an empty page with a title, and record which
  of the two the leaf does.
  - NOT RUN. This lane has no instance and the phase runs no Playwright. The
    question is asked by `tests/e2e/knowledge-base-on-the-case.spec.ts`, which
    reads the tab with an account outside the collective and refuses page
    content, so the answer lands in the nightly rather than in a guess here.
- [x] 4.1 `tests/vitest/`: the tab declares `requiredApp`, and dossiq stores
  no article text of its own.
  - `tests/vitest/caseKnowledgeBase.spec.js`, 7 assertions. `requiredApp` is
    asserted against the library's own descriptor rather than a copy of the
    string, because a copy cannot see the leaf dropping it. Mutation checked:
    pointing the widget at `wiki` reddens the assertion that names the leaf.
- [x] 4.2 `tests/e2e/knowledge-base-on-the-case.spec.ts`: a case type with a
  work instruction shows it on every case of that type.
  - Reads the caseType schema back from OpenRegister and writes and re-reads
    the value, because an undeclared property is answered 200 and dropped, and
    reading the register file would report green on exactly that.
