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
- [ ] 5.3 The other 26 are pre-existing dossiq debt that the gate fix in section
  4 would surface on the next PR touching those files. Not fixed here: each one
  needs a judgement about which scenario it meant, which is the work, not the
  sed.
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
