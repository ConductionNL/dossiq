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
  - ✅ CLOSED 2026-09-20. It was not a change of its own: it was a component,
    and the reason above is exactly the reason it had to be one. The tab is
    now a `case-sections` container holding
    `src/components/case/CaseWorkInstructionPanel.vue` over the linked-pages
    leaf. The panel follows the `caseType` reference once, reads
    `knowledgeBasePage` and draws a LINK, never the article.
    `tests/vitest/caseWorkInstruction.spec.js`, 11 assertions.
  - Three blank states are kept apart, because a reader cannot tell them apart
    from the panel and only one of them is nobody's problem: a type that names
    no page draws nothing, a lookup that FAILED says so with a retry, and a
    page that IS named draws the link. Mutation checked: swallowing the
    failure reddens the assertion that the panel says it could not be looked
    up.
  - A `javascript:` value is not opened. The property carries no `format` on
    purpose and the value lands in an `href`, so the scheme is whitelisted and
    anything else is read as a Collectives page path. Mutation checked:
    dropping the whitelist reddens the assertion that names it.
  - 🔴 REQ-CKB-04 WAS WRONG AND IS REWRITTEN. It said the Knowledge tab is
    absent without Collectives. Nothing in the library does that:
    `CnTabsWidget.resolvedTabs` renders a `CnTab` for every configured entry
    that names a widget definition, `CnDetailWidgetHost` renders nothing for
    an integration id the registry does not answer rather than removing
    itself, and a widget's own `requiredApp` draws a set-up state rather than
    an absence. What goes missing is the SECTION, heading and all, because
    `CaseSectionsWidget` drops the heading of a section that drew nothing. The
    e2e now asserts that instead of the tab.
- [x] 2.3 `#CaseTypeDetail`: a field to pick the work instruction page.
- [x] 3.1 Confirm on a running instance that a user outside the collective's
  team sees no page rather than an empty page with a title, and record which
  of the two the leaf does.
  - NOT RUN. This lane has no instance and the phase runs no Playwright. The
    question is asked by `tests/e2e/knowledge-base-on-the-case.spec.ts`, which
    reads the tab with an account outside the collective and refuses page
    content, so the answer lands in the nightly rather than in a guess here.
  - 🔴 STILL NOT RUN ON 2026-09-20, and this task is now checked as CLOSED
    RATHER THAN DONE, because leaving it open indefinitely is how a change
    never archives while nothing about it changes. `npx playwright test`
    refuses to start here: `tests/e2e/base-url.ts` has no default target on
    purpose, since the historic one was the SHARED development container and
    this suite seeds and deletes OpenRegister objects. The browser service is
    down on this box besides (`ConnectionRefused` on all three connections).
  - WHAT IS TRUE WITHOUT AN INSTANCE. dossiq adds no filter of its own and
    holds no article text, which the vitest asserts twice: the panel draws a
    link and never a body, and no dossiq schema carries article text. So
    whatever the leaf does for a reader outside the team is what Collectives
    itself does, in one place rather than two. WHICH of the two it does stays
    UNMEASURED, and the nightly is the arbiter.
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
