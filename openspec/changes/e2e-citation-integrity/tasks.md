# Tasks: repair the misleading e2e citations, worst first

**Ruled 2026-09-11 by Ruben: repair all 149, not only the safety-relevant ones.** The audit offered a safety-first subset as the cheaper option and it was declined, so groups 1 through 5 are all in scope. Group order still holds, because it orders by what a false proof costs.

**This change measured; the repair is running against it.** Each row below names citations by their `audit.csv` line, so the fix can be checked against the verdict that produced it.

## Execution, started 2026-09-11

Group 1 went out in three parallel streams, split by file so that no two touch the same spec file:

- `spec-coverage/deelzaak-support.spec.ts` and `spec-coverage/document-zaakdossier.spec.ts` (10 citations, rows 1.4 to 1.8 and 1.13)
- `spec-coverage/handler-vervanging-waarneming.spec.ts` and `spec-coverage/admin-settings.spec.ts` (8 citations, rows 1.1, 1.2, 1.3 and 1.12)
- the six scattered singletons (rows 1.9 to 1.11 and the `case-hours-leaf` line in 3.4)

Group 5's anchor defect went out beside them, as a change to the gate rather than to this repo. See the note under group 5.

Ordering is by what a false proof costs, not by how easy the fix is. Group 1 holds citations that read as evidence that a protection works. A reviewer who sees `@e2e …#self-substitution-is-rejected` on a green suite concludes the validation holds; nothing in the suite says it does. Group 2 holds the rest of the smoke, group 3 the partials, group 4 the broken anchors, group 5 the structural defects that let all of it through.

Two rules apply throughout.

**Make it true or take it down. Never leave a third state.** A citation whose test cannot be made to prove its scenario belongs either on a new test that does, or on the spec as a reason-bearing `@e2e exclude`. It does not belong on a page-load test with a comment explaining why.

**Watch every repaired test fail before calling it repaired.** Break the requirement, confirm the test reddens, restore. A guard test that has never been seen red is the defect this audit measured, one layer up.

## 1. Safety-relevant false proofs (23 citations: 12 smoke, 11 partial)

These say a refusal, a permission or a guard is proven. None of them prove one.

- [ ] 1.1 `admin-settings#regular-users-cannot-access-admin-settings`. The scenario names a regular non-admin user and requires HTTP 403. The test probes anonymous (`storageState: undefined`) and asserts `not.toBe(200)`. Rewrite against an authenticated non-admin principal and assert 403 exactly. An anonymous 401 proves nothing about a logged-in user, which is the case the scenario is about. Keep an anonymous probe as a second test if it is wanted, but it does not carry this anchor.
- [ ] 1.2 `handler-vervanging-waarneming#self-substitution-is-rejected`. Submit a substitution naming the same user as absentee and substitute. Assert the validation error and that no `substitution` object was created. The current test opens the form and reads its labels.
- [ ] 1.3 `handler-vervanging-waarneming#bulk-reassignment-is-coordinator-only`. Probe with a user who does not hold the coordinator role and assert the refusal. Asserting the button is visible to an authorised user is the wrong half of the scenario.
- [ ] 1.4 `deelzaak-support`, three refusals on one test: `#sub-case-of-sub-case-is-prohibited`, `#sub-case-creation-blocked-when-parent-case-is-closed`, `#sub-case-creation-blocked-when-parent-has-no-sub-case-types`. Each needs its own fixture (a sub-case, a closed case, a parent with no sub-case types) and an assertion that the Create sub-case control is absent. The present test treats "button absent" as one acceptable outcome of a happy path and returns, so a wrongly visible button passes.
- [ ] 1.5 `deelzaak-support#case-without-sub-case-type-support-hides-section`. The assertion is `expect(hasTable || hasEmpty).toBeTruthy()`, which requires the section to render. The requirement is that it must not. Invert it against a case type without sub-case support. The spec body says the feature is unimplemented, in which case the honest repair is `@e2e exclude` on the scenario with that reason, not a test.
- [ ] 1.6 `deelzaak-support#delete-parent-case-with-sub-cases-shows-warning` and `#delete-case-without-sub-cases-proceeds-normally`. The orphan warning is asserted inside `if ((await warning.count()) > 0)`, so a missing warning passes; the sibling auto-dismisses dialogs and falls back to the no-500 check. Seed both fixtures, assert the two dialogs differ, and assert `parentCase` is nulled on the orphans.
- [ ] 1.7 `document-zaakdossier`, four citations on unconditionally fixme'd tests: `#req-zak-005a` (dialog must not close before required fields), `#req-zak-005c` (executable uploads blocked by extension and magic bytes), `#req-zak-006b` (restore disabled on definitief), `#req-zak-008c` (per-document bulk result). The tests are blocked on a seeded case fixture (#764). Until that fixture exists, these anchors are claims nothing backs: move them to `@e2e exclude` naming #764, or land the fixture. The upload guard is the one to land first.
- [ ] 1.8 `document-zaakdossier#req-zak-006b` again, its second home in `case-documents.spec.ts`. The `toBeDisabled()` check sits in a loop over `restore.count()`, so a panel with zero Restore buttons is green. Assert the button exists before asserting it is disabled.
- [ ] 1.9 `first-time-setup` REQ-SETUP-PRO-001. The test asserts the wizard's step list does **not** contain `seed` while the cited requirement mandates a `seed` step. This is the only anti-coverage in the set: implementing the cited requirement turns the test red. Settle which of the two is current, then fix the other. Do not fix the test without reading the spec.
- [ ] 1.10 `case-types` cycle refusal. The test saves the cyclical parent successfully and asserts only that blueprint traversal terminates. The scenario requires the save to fail with a message naming the cycle. Assert the refusal and the message.
- [ ] 1.11 `friendly-case-create-form#req-fcf-003`. The test accepts the answer on `case.properties` OR as a `caseProperty` row, so the "never as properties of the case itself" clause cannot fail. If both stores really are live during a transition, the requirement is wrong and should say so; if not, assert the single store.
- [ ] 1.12 `handler-vervanging-waarneming#scope-limited-substitution-only-routes-matching-items`, `#all-actions-under-a-substitution-are-queryable`, `#timeline-shows-the-substituted-capacity`, `#preview-before-execution`. Four more on the same file, all page-load or affordance-present assertions. This file is the densest cluster in the audit: 9 of 9 citations non-verified, three of its five tests falling back to `test.skip` when a heading fails to appear. Rewrite the file against seeded substitutions or exclude its scenarios with reasons.
- [ ] 1.13 `document-zaakdossier#req-zak-004a` and `#req-zak-004b` on `cases index renders so a case dossier tab can be opened`. The opening example. The test loads `/cases` and asserts no 500. Neither anchor belongs on it.

## 2. Remaining smoke (23 citations)

Same repair, lower cost of being wrong. Most cluster on the same tests as group 1, so they come along with those fixes.

- [ ] 2.1 The nine unconditionally fixme'd citations: `case-management` REQ-CM-27 and `#scenario-cm-06a-case-info-panel`, `case-email-integration#composer-is-the-leaf-nc-mail…`, `deelzaak-support#case-list-shows-sub-case-count`, `#case-without-sub-cases-has-no-badge`, `#sub-case-counts-batch-loaded-per-page`, `bezwaar-beroep-workflow#…bezwaar-index-shows-only-bezwaar-cases…`, plus `document-zaakdossier#req-zak-004c` and `#req-zak-006a`. A `test.fixme(true, …)` body never runs. Gate-19 already reports this class, so the honest move is to convert each to a spec-side `@e2e exclude` naming its blocking issue until the fixture lands.
- [ ] 2.2 The remaining `document-zaakdossier` smoke: `#req-zak-005b` (per-file progress), `#req-zak-008a` (ZIP manifest and per-type folders). Same seeded-fixture dependency as 1.7.
- [ ] 2.3 `my-work#scenario-card-and-table-view`, `my-work` (anchorless, `work-navigation.spec.ts`), `add-work-queue#the-queue-holds-unassigned-open-cases`, `gis-integration#cases-on-map-view-renders-the-map-dashboard`, `dashboard#scenario-dash-v1-006a-board-columns-reflect-status-types`, `dashboard#scenario-you-complete-a-task-from-the-row`, `deelzaak-support#top-level-case-has-no-breadcrumb`. Each asserts a shell, a heading or an absent 500. Each needs the one assertion its scenario actually names.
- [ ] 2.4 `pdok-consumer#…openconnector-absent-surfaces-warning…` and `#…all-six-functions-are-exported…`. The first asserts a mocked 404 echoes its own status. The second asserts two seeded address rows read back and never inspects the shim's exports. A test that asserts its own mock is a tautology; rewrite both to call the shim.

## 3. Remaining partials (70 citations)

The 81 partials less the 11 already in group 1. Right surface, wrong assertion. Cheaper to repair than group 1 because the fixture and the navigation already work; usually one assertion is missing.

- [ ] 3.1 Work `audit.csv` filtered to `verdict=partial` and `safety_relevant=no`, file by file, and add the assertion the justification column names. The justification is written to be actionable: "asserts the tab is visible, never reads the count badge the requirement specifies" names the missing line.
- [ ] 3.2 Take the four densest files first, since they hold the concentration: `case-parties.spec.ts` (8 of 18 non-verified), `dashboard-tiles.spec.ts` (8 of 16), `case-task-pane.spec.ts` (4 of 12), `case-type-edit-and-setup.spec.ts` (14 of 15).
- [ ] 3.3 While in each file, remove the `if ((await x.count()) > 0)` guards around assertions that are meant to be unconditional. They are why a test is green on an empty fixture, and they are the mechanism behind most of this bucket.
- [ ] 3.4 `case-hours-leaf.spec.ts#the-leaf-reads-the-right-case` deserves its own line: it books 2.5 hours and asserts the headline rose by 2.5, which an unfiltered leaf showing every case's hours does too. Assert the `domainObjectRef` filter, not the delta. Note also that this whole branch registers only under `DOSSIQ_E2E_HUMANIQ=1`, which nothing in the repo or CI sets, so these citations currently rest on tests that never run in CI at all.

## 4. Dangling anchors (33 citations)

Mechanical, and the cheapest wins in the set. Fix the pointer, do not rewrite the test.

- [ ] 4.1 `case-communication.spec.ts`, 6 citations into `openspec/changes/contact-moments/specs/kcc-werkplek-zaaksysteem-bridge/spec.md`, which does not exist. The change was archived or renamed. These are strong tests with real stored-object assertions; repoint them at the canonical `openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md` anchors and check each lands on the requirement the test proves.
- [ ] 4.2 `case-type-edit-and-setup.spec.ts` and `case-types-tabs.spec.ts`, 9 citations at `#TASK-CT-13` and `#TASK-CT-08-SMOKE` in two archived `tasks.md` files. A task id is not a scenario and `tasks.md` is not a spec, so these were never valid citations even before the archive moved them. Find the scenario each test proves in `openspec/specs/case-types/spec.md` and cite that.
- [ ] 4.3 `dashboard-tiles.spec.ts` and `pages.spec.ts`, 8 citations at `dashboard#kpi-tiles-render-on-a-fresh-load`, `#one-work-table-with-days-left-and-row-actions`, `#view-all-keeps-the-tiles-filter`, `#the-case-type-list-on-new-case-is-sorted-and-filtered` and `signalering-widgets#one-deadlines-table-replaces-…`. Each names a **requirement** heading, not a scenario. Gate-19 slugs scenarios only. Repoint each at the scenario under that requirement.
- [ ] 4.4 `integrations-and-flows.spec.ts`, 7 citations into four spec directories that do not exist (`avg-processing-surface`, `ai-oversight-surface`, `admin-settings-surface`, `automatic-actions-surface`). The live copies sit under `openspec/changes/page-topology-cleanup/specs/`, which gate-19 cannot parse either. Decide whether those capabilities are canonical yet; until they are, the honest citation form is the one the gate can read.
- [ ] 4.5 The singletons: `pdok-consumer#scenario-suggest-call-reaches-openconnector-instead-of-api-pdok-nl`, `case-management#create-a-case`, `subsidieregeling-is-a-casetype/proposal.md`. A `proposal.md` is not a citable target at all.

## 5. The structural defects that let this through

Without these, the same 149 come back. Each is a hydra-side or convention-side decision, not a dossiq test fix, so they are listed last and separately.

- [ ] 5.1 **42 citations use a GitHub anchor the gate cannot read.** `#scenario-req-zak-004b-…` resolves when a human clicks it and credits zero in gate-19, whose slug has no `scenario-` prefix. Zero of the 42 are credited today. Either normalise the prefix in the gate, or rewrite all 42. A defect that is correct by the human check and wrong by the machine check will keep recurring until one of the two moves.

      **Ruled 2026-09-11: the gate moves, not the citations.** Confirmed
      against `document-zaakdossier/spec.md:179`, whose heading is
      `#### Scenario: REQ-ZAK-004b Empty dossier shows upload CTA with
      drag-and-drop zone`. GitHub slugifies the whole heading and keeps the
      leading word; `_SCENARIO_RE` in `check_e2e_coverage.py` captures only
      the text after `Scenario:` and slugifies that. The two differ by
      exactly the prefix.

      Rewriting the 42 would fix dossiq and leave the trap armed for every
      other repo, because copying the anchor out of the rendered spec is the
      natural gesture and it will keep producing this form. Teaching the gate
      to accept both is also the safe direction: it can only turn a
      non-credit into a credit, never a pass into a block. Out for review as
      a change to `.github`.
- [ ] 5.2 **79 citations carry no anchor.** `@e2e openspec/specs/<x>/spec.md` with no `#` names a file, not a requirement, and credits nothing. Propose that gate-19 reject an anchorless citation rather than ignore it, since ignoring it is what makes it survive review.
- [ ] 5.3 **23 citations point into `openspec/changes/**`.** Delta specs, `tasks.md`, `proposal.md`. The gate parses only `openspec/specs/`. Archiving a change silently breaks every one of them, which is how group 4 was created. Either teach the gate to resolve change-local specs, or require citations to name the canonical spec.
- [ ] 5.4 **26 citations claim a scenario the spec itself marks `@e2e exclude`.** Two statements contradict, nothing detects it, and the gate silently discards the test's claim. Add a check that flags a scenario carrying both.
- [ ] 5.5 **Reconcile the counts.** 330 citations resolve onto 180 distinct scenarios. Citation count is not coverage and reads 1.8x high. If a citation count appears on any dashboard, replace it with the distinct-scenario count.
- [x] 5.6 **Re-run this audit after the repair.** Done 2026-09-12, pinned to `4a4fdb1e`. See `remeasurement-2026-09-12.md` for the report, `audit-2026-09-12.csv` for the rows and `worklist-2026-09-12.md` for what is left. The 2026-09-11 file is kept as `audit-2026-09-11.csv`.

      Verified 236 of 316, against 181 of 330. Gate-credited 202 of 316, against
      156 of 330. Non-verified citations fell from 149 to 80.

      **Dispatch the next wave from `worklist-2026-09-12.md`, never from the old
      CSV.** Filtering `audit-2026-09-11.csv` on `verdict != verified` still
      returns 149 rows and 69 of them are repaired. Groups 1 through 4 above are
      superseded by that worklist; the items in group 5 that are still open are
      5.2, 5.3 and 5.4, and 5.4 got worse rather than better (26 contradictions
      then, 31 now).

      Two cautions the report states in full. 48 of the 55 newly verified
      citations are new citations rather than repaired ones, so the headline
      overstates how much of the old debt was paid. And half of the gate-credit
      gain is the gate: on the 2026-09-11 tree the current gate already credits
      184 of 330 without a line of dossiq changing.
