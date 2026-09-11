---
kind: measurement
---

# Proposal: e2e-citation-integrity

## Summary

dossiq's Playwright suite carries 330 `@e2e` citations across 52 files and 270 tests. Gate-19 checks that a citation exists. Nobody has ever checked that the cited test verifies the cited requirement. This change is the measurement: every one of the 330 citations classified by the mutation question, "if I broke exactly what this requirement states, would this test go red?"

**181 citations survive that question. 149 do not.** 81 drive the right surface and assert around the requirement, 35 assert only that the page loaded, and 33 point at a spec file or anchor that does not exist. `audit.csv` beside this file carries the per-citation verdict, the decisive assertion quoted from the test, and a one-line reason.

No test and no spec is touched by this change. `tasks.md` proposes the repair in priority order. The repair is a separate decision.

## Motivation

The trigger was one test. `tests/e2e/spec-coverage/document-zaakdossier.spec.ts` declares:

```ts
// @e2e .../spec.md#req-zak-004a-dossier-groups-documents-by-type-with-count-badge
// @e2e .../spec.md#req-zak-004b-empty-dossier-shows-upload-cta-with-drag-and-drop-zone
test('cases index renders so a case dossier tab can be opened', ...)
```

Its whole body loads `/cases` and asserts the page does not say `Internal Server Error`. It never opens a dossier, never reads a count badge, never looks for an upload call to action. Two requirements read as covered. Neither is.

Gate-19 cannot catch this and was never built to. It matches an anchor string against a scenario slug. A string match is not a proof, and the gate's own report says so honestly: it credits 98 of dossiq's 2,764 scenarios, 6.7 percent. The risk is not the 93 percent the gate reports as uncovered. Everyone can see that number. The risk is the slice the gate reports as covered while nothing verifies it, because that slice is invisible by construction.

This matters most where the requirement is a refusal. A smoke citation on a layout requirement costs a reader two minutes. A smoke citation on `Self-substitution is rejected` or `Regular users cannot access admin settings` reads as evidence that a protection works. Twelve of the 35 smoke citations and 11 of the 81 partials sit on requirements of that kind.

## What was measured, and how

**The population.** Every `@e2e` directive in `tests/e2e/**/*.spec.ts`, parsed with gate-19's own tokeniser (`check_e2e_coverage.py`) so the set matches what the gate sees. Prose mentions of `@e2e` in block comments are excluded, as the gate excludes them. `@e2e exclude` markers in tests are excluded: they claim nothing. That leaves **330 citations, 52 files, 270 tests, 180 distinct scenarios**.

**The judgment.** Each citation was paired with the full text of the scenario it cites and the full source of the test that owns it, then read against the mutation question. Four independent readers worked disjoint batches under one rubric, with the verdict forced when the citation does not resolve. Every verdict quotes the decisive assertion.

**The verdicts.**

| Verdict | Count | Share | Meaning |
|---|---:|---:|---|
| verified | 181 | 54.8% | A real breakage of the requirement reddens this test. |
| partial | 81 | 24.5% | Right surface, wrong assertion. Every assertion survives the breakage. |
| smoke | 35 | 10.6% | Page loads, no 500, nothing about the requirement. |
| dangling | 33 | 10.0% | The cited file or anchor does not exist. |

**What the number means.** 55 percent of dossiq's citations are honest. That is the headline, and it is better than the opening example suggested. The other 45 percent is not uniformly worthless: a `partial` still drives the right page, which is worth something on a fleet where most surfaces have no test at all. What no citation in those 149 does is prove its requirement. When `openspec` says a scenario is covered, 45 percent of the time the word means "somebody wrote the anchor down".

The count of citations is not the count of scenarios. 330 citations resolve onto 180 distinct scenarios, so tests double-cite heavily. `case-objects.spec.ts` cites all eight of its scenarios twice, once in each grammar. Reading a citation count as a coverage figure inflates it by roughly 1.8x before any of the above is applied.

## Four structural findings that are not about any single test

**1. 102 citations are invisible to the gate.** Gate-19 parses two forms: `openspec/specs/<spec>/spec.md#<slug>` and `<spec>::<slug>`. 79 citations carry a path with no `#anchor` at all, and 23 point into `openspec/changes/**` (delta specs, `tasks.md`, `proposal.md`). The gate parses none of these and credits none of them. They read exactly like the other 228 to a human reviewer. In total the gate credits **156 of 330** citations as covering a real scenario.

**2. 42 citations use a GitHub anchor where the gate wants a slug.** Writing `#scenario-req-zak-004b-…` produces a link that works when you click it in the GitHub rendering of the spec, and credits nothing: the gate's slug is `req-zak-004b-…` with no `scenario-` prefix. Zero of the 42 are credited. This is the worst kind of defect, because the citation is verifiably correct by the check a human performs and wrong by the check the machine performs.

**3. 26 citations claim a scenario the spec itself declares out of e2e scope.** The scenario carries `@e2e exclude <reason>` ("covered by StatusChecklistGuardTest", and similar) and a test cites it anyway. One of the two statements is stale. Nothing detects the contradiction, and gate-19 counts the scenario as excluded, so the test's claim is silently discarded.

**4. 16 citations sit on tests that cannot run.** `test.fixme(true, …)` with no condition, five tests between them. Gate-19 already catches this class and reports it, so these are visible if anybody reads the report. They are listed here because the anchors are still in the file, and a reader of the file sees a claim.

## The ten worst citations

Ranked by what a false proof costs, not by how wrong the test is. A refusal, a permission and a guard come first, because the citation reads as evidence that a protection holds.

| # | Citation | The test, and why it cannot fail |
|---|---|---|
| 1 | `admin-settings#regular-users-cannot-access-admin-settings` | The scenario names a regular non-admin user and requires HTTP 403. The test builds a context with `storageState: undefined`, so it probes **anonymous**, and asserts `res.status()).not.toBe(200)`. Grant every authenticated user the admin page and this stays green. |
| 2 | `handler-vervanging-waarneming#self-substitution-is-rejected` | Test opens the substitution form and asserts the `Substitute (user id)` field is visible. A self-substitution is never submitted, so the validation that must reject it is never run. |
| 3 | `handler-vervanging-waarneming#bulk-reassignment-is-coordinator-only` | Runs as an authorised user and asserts the bulk-reassign button is visible. No user without the coordinator role is ever probed. |
| 4 | `deelzaak-support#sub-case-of-sub-case-is-prohibited` | The test treats "button absent" as a valid state, annotates it and returns. If the Create sub-case button wrongly appeared on a sub-case, the test takes the happy path and passes. Two sibling refusals, `#sub-case-creation-blocked-when-parent-case-is-closed` and `#…-parent-has-no-sub-case-types`, sit on the same test with the same hole. |
| 5 | `deelzaak-support#case-without-sub-case-type-support-hides-section` | The requirement is that the section MUST NOT render. The assertion is `expect(hasTable \|\| hasEmpty).toBeTruthy()`, which requires that it DID render. The citation asserts the inverse of the requirement it cites. |
| 6 | `document-zaakdossier#req-zak-005c-file-validation-blocks-executable-uploads` | `test.fixme(true)` plus a no-500 check on `/cases`. An upload guard against executables by extension and magic bytes is traced to a test that never executes. Three siblings on the same test (`#req-zak-005a`, `#req-zak-006b`, `#req-zak-008c`) are the same. |
| 7 | `first-time-setup` (REQ-SETUP-PRO-001) | The test asserts the wizard's step list does **not** contain `seed`, while the cited spec mandates a `seed` step. Implementing the cited requirement turns this test red. It is anti-coverage counted as coverage. |
| 8 | `case-types` cycle refusal | The test successfully saves the cyclical parent and then asserts only that blueprint traversal terminates. The spec requires the save to fail with a message naming the cycle. |
| 9 | `friendly-case-create-form#req-fcf-003` | The requirement says answers are written as `caseProperty` rows, **never** as properties of the case. The test accepts either store, with a comment saying both are live during the transition. The invariant that is the point of the requirement cannot fail. |
| 10 | `kcc-werkplek-zaaksysteem-bridge` (6 citations, `case-communication.spec.ts`) | Dangling: `openspec/changes/contact-moments/specs/…/spec.md` does not exist. These are among the strongest-written tests in the suite, with real stored-object assertions, pointed at a file that was archived or renamed. Good work made unfindable. |

Beyond the ten, the concentration is worth naming. Four files account for 49 of the 149 non-verified citations and have no verified citation at all between them: `spec-coverage/deelzaak-support.spec.ts` (16 of 16), `spec-coverage/document-zaakdossier.spec.ts` (10 of 10), `spec-coverage/handler-vervanging-waarneming.spec.ts` (9 of 9) and `case-type-edit-and-setup.spec.ts` (14 of 15). Every file under `tests/e2e/spec-coverage/` was written to satisfy gate-19, and the pattern shows: the files written to prove scenarios prove fewer of them than the files written to test features.

## Limits of this method

State these before quoting any number above.

**No mutation was actually run.** Every verdict is a reading of the test source against the spec text, not an experiment. A `verified` is a claim that the test would redden, not a demonstration that it did. Running the suite against deliberately broken builds is the only way to settle that, and it is out of scope here.

**Four readers, one rubric, no overlap.** Batches were disjoint, so no citation was judged twice and inter-reader agreement is unmeasured. The verified share per batch ran 51, 53, 54 and 60 percent, which is consistent enough to suggest the rubric held, and is not evidence that it did. Two headline rows were re-checked by hand against the source and both stood.

**The verified / partial line is a judgment, not a measurement.** The rubric resolves ties toward `partial`, so the verified count is a floor rather than a point estimate. The smoke and dangling counts are firmer: dangling is decided mechanically by resolution, and smoke is close to mechanical.

**Anchorless citations were judged generously.** 79 citations name a spec file with no anchor, so there is no single requirement to test them against. Each was judged against the spec as a whole, which is the reading most favourable to the citation. A stricter rule, that a citation naming no requirement proves no requirement, would move most of those 79 out of `verified`.

**This measures citations, not coverage.** A requirement with no citation at all is outside this audit. Gate-19 reports 1,363 such scenarios in dossiq. This change says nothing about them.

**Verified does not mean well tested.** It means one assertion would catch one breakage. Several `verified` citations sit on tests that also carry `if (count > 0)` guards elsewhere in the body, and pass silently on an empty fixture for everything except the one clause that earned the verdict.

## Scope

### In scope

1. **The measurement.** `audit.csv`, 330 rows, one per citation: cited spec path, anchor, test file, test name, verdict, justification, safety relevance, whether gate-19 credits it, how the citation resolves, and the decisive assertion quoted from the test.
2. **This report.**
3. **`tasks.md`.** The repair in priority order, safety-relevant false proofs first. Proposed, not performed.

### Out of scope

- **Every repair.** No test and no spec is modified by this change. What to fix, and whether to fix rather than delete a misleading citation, is the user's call and depends on these findings.
- **Gate-19 itself.** Two of the structural findings (the `scenario-` prefix that credits nothing, the `openspec/changes/**` paths the gate cannot parse) are arguably gate defects rather than dossiq defects. They belong in a hydra change, and they are named here only because they change what dossiq's numbers mean.
- **Uncited scenarios.** The 1,363 scenarios with no citation at all are a coverage question, not an integrity question.
- **The rest of the fleet.** dossiq carries the most citations, so it was measured first. Whether 55 percent is the fleet's number or dossiq's is unknown.

## Affected projects

- [x] Project: `dossiq`. This change. Measurement only.
- [ ] Project: `hydra`. Gate-19 owns the two parsing findings above. No change is requested here; a separate proposal should decide whether the gate should reject an anchor it cannot resolve, which is the mechanical floor this audit had to supply by hand.

## Risks

The measurement's own risk is that it gets quoted without its limits. "45 percent of dossiq's e2e citations are hollow" is the sentence that will travel, and it is a reading of source code, not a run of an experiment. The number that survives the strictest challenge is the dangling 33: those are decided by file-system resolution and nothing else.

The second risk is the repair. The cheapest way to raise the verified share is to delete the weak citations, which raises the ratio and lowers the coverage. `tasks.md` orders the work so the safety-relevant ones are made true rather than removed, and says which are honest deletions.

## Validation

`openspec validate e2e-citation-integrity --type change --strict` passes. This change declares no spec deltas, so it carries `skip_specs: true` in `.openspec.yaml`, which is the convention the openspec CLI names in its own error text and the shape `2026-09-09-proposals-are-cases` already used. No placeholder capability was invented. For the record, 8 of the 23 existing changes in `openspec/changes/` fail `--strict` today for exactly the missing-`skip_specs` reason; that is pre-existing and untouched here.
