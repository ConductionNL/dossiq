---
kind: measurement
---

# Re-measurement 2026-09-12: what the repair actually moved

Task 5.6 of this change. The first audit measured 330 citations on 2026-09-11 and
proposed a repair. Five repair PRs and two gate changes have landed since. This
re-measures the same population against the same rubric, so the next repair wave is
dispatched from what is true today.

Pinned to `4a4fdb1e`. Anything merged after that is unmeasured, and the last section
says what that costs.

**Do not filter the old file.** `audit-2026-09-11.csv` filtered on `verdict != verified`
returns 149 rows. 80 of them are still open. Dispatching from that file redoes 69
citations of work that has already landed.

## The four verdicts, then and now

| Verdict | 2026-09-11 | 2026-09-12 | |
|---|---:|---:|---|
| verified | 181 | 236 | a real breakage of the requirement reddens this test |
| partial | 81 | 52 | right surface, every assertion survives the breakage |
| smoke | 35 | 20 | page loads, no 500, nothing about the requirement |
| dangling | 33 | 8 | the cited file or anchor does not exist |
| **total** | **330** | **316** | |

The verified share goes from 54.8 to 74.7 percent. Non-verified citations go from 149
to 80, a fall of 46 percent.

The population shrank by 14 because repairs consolidated duplicate citations and
withdrew several that were claiming scenarios the spec had since excluded. 73 of the
330 are gone and 59 are new, so 257 citations stood through both readings.

## Where each citation went

Rows are the 2026-09-11 verdict, columns the verdict today.

| then \ now | verified | partial | smoke | dangling | total |
|---|---:|---:|---:|---:|---:|
| verified | 172 | 8 | 1 | 0 | 181 |
| partial | 15 | 32 | 4 | 0 | 51 |
| smoke | 1 | 1 | 9 | 0 | 11 |
| dangling | 0 | 4 | 2 | 8 | 14 |
| not in that population | 48 | 7 | 4 | 0 | 59 |
| **total** | **236** | **52** | **20** | **8** | **316** |

Read the bottom row before the diagonal. 64 citations are verified now and were not
verified before. **48 of those 64 did not exist before.** The repair mostly wrote new tests and cited them,
rather than strengthening tests that were already cited. Of the 143 citations the
first audit called non-verified and that still stand today, 16 moved up to verified
and 13 moved down.

The gone column is the other half of the story. 73 citations were withdrawn: 30
partial, 24 smoke, 19 dangling, and **zero verified**. Nothing of value was deleted to
raise the ratio, which was the risk `tasks.md` named.

## What got worse

Thirteen citations that stood in both readings lost ground. None of them is a
regression in the product; each is either a test rewritten around a different claim,
or a first reading that was too generous. They are listed because a repair that
weakens a citation is the thing most worth catching, and because each is a one-line
fix.

| Movement | Citation | Why |
|---|---|---|
| verified to partial | `features-roadmap#a-reader-can-date-the-claim` | `toContainText('2026')` runs over the whole comparison container, and a second sentence in it already carries a 2026 date. Deleting the compared-on date leaves it green. |
| verified to partial | `case-dashboard-view#the-number-reads-under-the-title` | The scenario is about the subtitle, which `config.subtitleField` drives. The test reads a `dd` in `CaseHeaderRow.vue`. Two mechanisms print the number and the test guards the one the scenario does not name. |
| verified to partial | `task-management` (anchorless, two rows in `case-list-lenses.spec.ts`) | Both call OpenRegister's flow-task endpoint directly and assert the engine's own `dueAfter` and `overdue` semantics. A broken chip on the dossiq page leaves both green. |
| verified to partial | `initiator-selection` and `initiator-display` (anchorless, `case-requester.spec.ts`) | One reads back the row `beforeAll` seeded and asserts the fields the fixture wrote. The other proves narrowing by a direct API query, and its one UI assertion is satisfied by the column header. |
| verified to partial | `avg-processing-surface#scenario-procest-hosts-no-processing-activities-page` | Asserts one English heading is absent and never reads the manifest. Worth reading twice: `AvgRegisterLink` in `src/manifest.json` carries no `section` key, so one of the scenario's own THENs is already false while the test is green. |
| verified to partial | `brp-kvk-initiator#contacts-source-degrades-gracefully` | The GIVEN is never established. `/contactsmenu/contacts` is a core endpoint and answers 200 with no matches, so the `catch` the requirement is about is never entered. |
| verified to smoke | `doorlooptijd-dashboard#doorlooptijd-page-renders-heading` | Asserts a heading and the absence of a 500. The scenario names a heading the page stopped rendering. |
| partial to smoke | `admin-settings#in-app-settings-page-renders-configuration-sections` | The in-app page the requirement names is retired. The test drives the admin route and asserts one Save button. |
| partial to smoke | `handler-vervanging-waarneming#waarnemer-sees-substituted-work-in-my-work` | The substituted toggle is clicked inside an `if` that skips when it is absent. The only unconditional assertions are a button and no 500. |
| partial to smoke | `dashboard#scenario-dash-v1-006a-board-columns-reflect-status-types` (in `workflow-operations.spec.ts`) | `.board-column, .workflow-board__empty` is an or, so a board with zero columns passes. The sibling citation in `case-lifecycle.spec.ts` was rebuilt and does verify this scenario. |
| partial to smoke | `case-map-overview#scenario-overview-01a` | `.leaflet-container, [class*="map"]` is satisfied by almost any wrapper. |

## What the gate says, and where we disagree

`check_e2e_coverage.py --mode report` against `4a4fdb1e`:

| | 2026-09-11, gate of that day | 2026-09-11 tree, current gate | 2026-09-12 |
|---|---:|---:|---:|
| scenarios | 2,764 | 3,427 | 3,434 |
| covered | 98 | 113 | 131 |
| excluded | 1,303 | 1,536 | 1,547 |
| uncovered | 1,363 | 1,778 | 1,756 |
| coverage | 6.7% | 6.0% | 6.9% |

The middle column is the same tree the first audit read, measured with today's gate.
It separates the two effects. **The gate change made 663 more scenarios visible and
credited 15 more of them. The repair credited 18 more on top of that.** Quoting 98 to
131 as a repair result would be wrong by about half.

At citation level the gate credits **202 of 316**, against 156 of 330 that day. On the
2026-09-11 tree the current gate credits 184, so again: 28 of the 46 is the gate, 18 is
the repair.

**Where our reading and the gate's disagree, and which is right.**

- 77 citations we call `verified` credit nothing in gate-19, 76 of them because they
  carry no anchor. The gate is right about credit and we are right about the test.
  A citation naming a whole spec file proves no particular requirement, and the rubric
  judged those generously on purpose, against the spec as a whole. Read the 236 as
  159 gate-creditable verified plus 77 tests that work and are pointed at nothing in
  particular.
- 12 citations land on a **requirement** heading rather than a scenario heading. They
  resolve when a human clicks them and credit nothing, because gate-19 slugs scenarios
  only. Eleven of the twelve are one file, `case-create-form.spec.ts`, and nine of
  those eleven have a scenario one level down that states exactly what the test
  proves. That is an eleven-line re-anchor worth doing first.
- One citation misses by slug spelling alone: `pdok-consumer` has a scenario about
  reaching OpenConnector instead of `api.pdok.nl`, and the gate slugs the dots away
  into `apipdoknl` while the citation writes `api-pdok-nl`. The citation is wrong and
  the gate is right, and no repair to any test fixes it.
- 31 citations claim a scenario the spec itself marks `@e2e exclude` with a reason, up
  from 26. The gate discards the test's claim silently. This is the one structural
  number that got worse, and five of the increase are one new file,
  `vth-inspection-result-authz.spec.ts`, which cites five `inspection-checklists`
  scenarios that all carry an exclusion. One of the two statements is stale in every
  case, and nothing detects the contradiction. Task 5.4 is still open and is now more
  expensive than it was.

## The structural findings, re-counted

| | 2026-09-11 | 2026-09-12 |
|---|---:|---:|
| carry no anchor, so name a file and not a requirement | 79 | 76 |
| point into `openspec/changes/**` | 23 | 14 |
| land on a heading that is not a scenario | 12 | 12 |
| sit on a test that never runs | 16 | 4 |
| claim a scenario the spec marks `@e2e exclude` | 26 | 31 |
| gate-19 credits | 156 of 330 | 202 of 316 |
| distinct scenarios addressed | 169 | 182 |

The `scenario-` prefix defect is closed at the gate, as ruled on 2026-09-11: 42
citations use the GitHub spelling, zero were credited then, 29 are credited now and
the rest miss for other reasons.

## What remains

80 citations across 32 files. `worklist-2026-09-12.md` beside this file carries every
one with its justification. The concentration:

| File | Not verified | Of |
|---|---:|---:|
| `case-type-edit-and-setup.spec.ts` | 11 | 15 |
| `spec-coverage/deelzaak-support.spec.ts` | 8 | 18 |
| `spec-coverage/integrations-and-flows.spec.ts` | 8 | 9 |
| `spec-coverage/document-zaakdossier.spec.ts` | 6 | 9 |
| `spec-coverage/pdok-via-openconnector.spec.ts` | 4 | 4 |

Three groups are worth dispatching before the long tail.

**The eight dangling are two decisions, not eight repairs.** Seven cite four
`openspec/specs/*-surface/spec.md` files that do not exist; all four live under
`openspec/changes/page-topology-cleanup/specs/`, so archiving that change settles all
seven at once. The eighth is the `api.pdok.nl` slug.

**Four safety-relevant citations are still not verified**, down from 23. Restore on a
final document asserts inside a loop over `restore.count()`, so a panel with no restore
buttons is green. The sub-case deletion pair only ever exercises the orphan branch. The
inspection-result submit asserts `not.toBe(403)`, which a 500 also satisfies, and never
checks what was stored. And the setup wizard test still asserts the inverse of the
requirement it cites, which is the one piece of anti-coverage that survived: the spec
is the stale half there, not the test.

**Eleven of the twelve requirement-anchor citations are one file.** Nine become
gate-credited verified by moving the anchor one heading down. Two need more: one cites
a requirement about layout while testing sentence case, a rule no scenario states, and
one reads `created.properties` before falling back to `caseProperty` rows, so a build
that stores answers on the case passes the requirement that forbids exactly that.

## Limits of this method, restated

The first audit's limits still hold and are not repeated here. Four are new or changed.

**Still no mutation was run.** Every verdict is a reading of source against spec. Three
files now carry `MUTATION CHECK, NOT YET RUN` banners in their own headers, and those
verdicts are reasoned rather than demonstrated.

**Eight readers, one rubric, disjoint batches, and one of them was anchored.** The
first five batches were handed the previous verdict in their input. That invites a
reader to agree with it. Batch one returned exactly the prior distribution, which is
consistent with its reported movements but cannot be distinguished from anchoring.
The column was removed for batches six to eight and for the whole re-reading pass.
Treat the movement counts in the first five batches as a floor on how much changed.

**Development moved twice while the reading ran.** #2507 landed before the first pass
and #2508 during it. Every citation in a file either PR touched was read again against
`4a4fdb1e`, 81 of the 316. The rest were read at `dd050ae5`, which differs from the
pinned commit in no file carrying a citation.

**`dangling` is defined as the 2026-09-11 audit defined it:** the pointer resolves to
nothing, because the file is absent or the anchor matches no heading in it. Two other
shapes were graded on merit instead, and both were graded on merit in the baseline
too: an anchor landing on a non-scenario heading, and a citation into an existing
`openspec/changes/**` file. The six `TASK-CT-13` citations are the visible consequence:
the baseline called them dangling because a task id is not a scenario, this reading
calls them partial and smoke because the file and the heading both exist. That is a
convention difference, not a repair.

## Next

Dispatch the next wave from `worklist-2026-09-12.md`, not from `audit-2026-09-11.csv`.
Take the four safety-relevant rows and the eleven-line re-anchor first, then archive
`page-topology-cleanup` to settle seven of the eight dangling in one move.
