# Tasks: spec-anchors-name-the-capability

## 1. Measure before rewriting anything

- [x] 1.1 Count the anchors and how they resolve, using the gate's own rule
  rather than `os.path.exists()`.
  - 6,244 anchors: 5,958 `@spec`, 286 `@e2e`. A literal-path check calls 2,955
    of them dangling. Gate 46's resolver, run over 1,698 files, reports zero.
- [x] 1.2 Mutation-check the resolver before believing its zero.
  - planted `@spec openspec/changes/definitely-not-a-real-change/tasks.md#task-1`
    and `@spec openspec/specs/definitely-not-a-real-capability/spec.md`; both
    reported as "target file not found", then restored and the diff confirmed
    clean. A zero from an instrument that cannot fail is not a measurement.
- [x] 1.3 Widen the check to `@e2e`, which gate 46 does not read, and find what
  it was hiding.
  - six anchors across two test files, naming four capabilities with zero homes.

## 2. Fix what is actually broken

- [x] 2.1 The six dangling anchors are fixed. Not here: #2067 landed them first,
  and better. This branch's own edits were dropped and the upstream version
  taken whole on merge.
  - #2067 anchors at the SCENARIO rather than the requirement, and uses
    `@e2e exclude` with a reason for the two with no home. This branch had
    pointed those two at the nearest plausible capability, which resolves and is
    worse than not resolving: it claims coverage that does not exist.
- [x] 2.2 Adopt #2067's rule into the convention rather than the one this branch
  started with.
  - requirement 4, "A test with no requirement to cite says so, rather than
    citing a near-miss".

## 3. Write the convention down

- [x] 3.1 Record the rule, the three homes, and the pending-capability case as
  `spec-anchor-convention`.
- [x] 3.2 Record why it is not a path rule, with the number that settles it: 107
  anchors name a capability whose only home is an open change, and a path rule
  breaks all 107 the day it lands.
- [x] 3.3 Record why #2063's 55 repointed citations were right work even though
  nothing was failing: a change directory names no capability, so the archive
  index rescues it by change name, which is a weaker guarantee than the rule.

## 4. Raise upstream, not here

- [x] 4.1 Filed as ConductionNL/.github#726. Gate 46 reads only `@spec`. Its pattern is `@spec\s+(openspec/...)`,
  so 286 `@e2e` anchors in this repo are checked by nothing. This is why the six
  were found by hand, twice, rather than by CI once. Belongs in
  `ConductionNL/.github`, `hydra-gates/scripts/lib/check_spec_anchors.py`.
- [x] 4.2 Filed as ConductionNL/.github#727. Gate 46 enumerates `lib src tests`. Two anchors under `appinfo/` and
  `scripts/` are never opened. Same helper.

## 5. The claim this change first made was wrong, twice over

- [x] 5.1 "6,244 anchors, zero unresolvable" was measured with a REIMPLEMENTATION
  of the resolver, not the resolver. Mine ignored two things the real one does:
  the flat `openspec/specs/<cap>.md` spelling, and fragment checking. It called
  13 of planix's anchors dangling that are fine, and it called 28 of this repo's
  `@e2e` anchors fine that are not.
  - the real resolver, run over all 284 `@e2e` targets here, reports 28
    unresolved. Every one is "anchor not found": the file resolves and the
    `#fragment` names a heading nobody wrote.
- [x] 5.2 Two of the 28 were broken by this session's own #2057, and nothing
  caught it.
  - #2057 renumbered the kanban delta's scenarios from `DASH-V1-006d/e` to
    `006f/g` to clear a collision with two scenarios the spec already had.
    `tests/e2e/spec-coverage/kanban-board-keyboard-status-transition.spec.ts`
    still cited `006d` and `006e`. Gate 46 did not look, because they are `@e2e`.
    Repointed at the canonical `openspec/specs/dashboard/spec.md` per the rule
    this change writes down.
- [x] 5.3 The other 26 are done, and they were not 26 of the same thing. 16 were
  dossiq's, and 10 were the gate's.
  - **16 repointed or excluded, one judgement each.** Nine now name the scenario
    they prove: three My Work tests at `Card and table view`, three workflow-editor
    tests at `Keyboard node selection` / `Keyboard add status node` / `Open workflow
    editor for a case type`, and three lifecycle tests at `A handler advances a
    case`, `DASH-V1-006a` and `Successful transition with audit trail`. One case
    detail test moved to `CM-06a Case info panel`. Six more, in the same file, only
    ever named a change directory and now name the capability, which is the rule
    this change writes down.
  - **Five carry `@e2e exclude` with a reason.** The objection-committees settings
    page, the settings-shell loop, the dashboard console-error leg, the
    current-status-name API leg and the edit-the-title leg each prove something no
    scenario states. Each names what is missing rather than the nearest plausible
    capability, per requirement 4.
  - Mutation-checked: four fragments were replaced with headings nobody wrote and
    all four were reported, then restored. A repointed anchor that resolves is
    evidence only if the resolver can still say no.
- [x] 5.5 The last 10 are not dossiq debt at all. They are gate 46 refusing to look
  where the requirement is.
  - All 10 are in `tests/e2e/case-documents.spec.ts` and name REQ-ZAK-011, -012,
    -013, template-library REQ-005 and beschikking-generatie REQ-BES-012. Every one
    of those requirements exists, in the open `documents-on-the-case` change's
    delta. The anchors are right.
  - `resolve()` consults the capability index "LAST RESORT ONLY", so a tag whose
    literal path exists is judged against that file and nothing else. These
    capabilities all HAVE a canonical spec; what is pending is the requirement
    inside it. So the file resolves, the fragment is looked for in the canonical
    spec alone, and the open change's delta is never opened.
  - That is the same widening this change's second requirement asks for, one level
    down: resolution is by capability across the three homes, and a capability
    whose canonical spec exists is not thereby finished. Filed as
    ConductionNL/.github#730, beside 4.1 and 4.2, against the same helper.
- [x] 5.4 Stop reimplementing the instrument. Every wrong number in this
  investigation came from a hand-rolled resolver; every correct one came from
  running `check_spec_anchors.py`. `@e2e` was measured by rewriting the tags to
  `@spec` in a probe file outside the repo and running the real helper against
  it, which is how the fleet numbers in section 4 were taken.

## 6. Process, recorded because it cost a branch

- [x] 6.1 Search before building. `git log origin/development -5 -- <path>` comes
  first: two PRs on this exact topic landed while this branch was being written,
  and the PR went out CONFLICTING with 4 of 49 checks passing, which is the shape
  a conflicting PR always has and reads green.

## 7. What the convention does NOT claim

- [x] 7.1 Measure the gap between the rule and the tree, so archiving this change
  is not read as the tree conforming to it.
  - 3,206 anchors name a path under `openspec/changes/`, against 3,039 naming
    `openspec/specs/`. The largest single group is 419 naming
    `retrofit-2026-05-24-case-management`, archived months ago. Every one of them
    resolves today, through the archive index, which is the weaker guarantee
    requirement 1 describes.
  - This change writes the rule and fixes what was measurably broken. Repointing
    3,206 resolving citations is a mechanical rewrite across `lib`, `src` and
    `tests` and belongs in its own change, where the diff can be read. Smuggling
    it into an archive PR is how a rewrite of that size stops being reviewable.
