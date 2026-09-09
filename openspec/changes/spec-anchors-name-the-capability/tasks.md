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

- [x] 2.1 Repoint the six dangling `@e2e` anchors at capabilities that exist.
  - `workflow-board` to `dashboard#REQ-DASH-V1-006` (2), `case-map` to
    `case-map-overview#REQ-OVERVIEW-01`, `bezwaar-management` to
    `bezwaar-lifecycle#bezwaren-list-surface` and `#bezwaar-status-types`,
    `subsidy-intake` to `subsidieverlening-keten`.
- [x] 2.2 Verify every candidate fragment resolves BEFORE writing it, not after.
  - probed all six through `check_spec_anchors.py` in a scratch file first.
- [x] 2.3 Re-run the whole-tree check and require zero.
  - 6,244 anchors, zero unresolvable, and gate 46 reports zero findings.
- [x] 2.4 Say in the subsidies test that no requirement covers its surface yet,
  so the anchor names a capability rather than a heading nobody wrote.

## 3. Write the convention down

- [x] 3.1 Record the rule, the three homes, and the pending-capability case as
  `spec-anchor-convention`.
- [x] 3.2 Record why it is not a path rule, with the number that settles it: 107
  anchors name a capability whose only home is an open change, and a path rule
  breaks all 107 the day it lands.

## 4. Raise upstream, not here

- [ ] 4.1 Gate 46 reads only `@spec`. Its pattern is `@spec\s+(openspec/...)`,
  so 286 `@e2e` anchors in this repo are checked by nothing. Belongs in
  `ConductionNL/.github`, `hydra-gates/scripts/lib/check_spec_anchors.py`.
- [ ] 4.2 Gate 46 enumerates `lib src tests`. Two anchors live under `appinfo/`
  and `scripts/` and are never opened. Same repo, same helper.
