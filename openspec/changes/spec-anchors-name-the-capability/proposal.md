# Proposal: spec-anchors-name-the-capability

## Why

Archiving 46 changes on 2026-09-09 looked like it had broken thousands of `@spec`
and `@e2e` anchors. A change directory moves on archive, from
`openspec/changes/<name>/` to `openspec/changes/archive/<date>-<name>/`, and
2,955 anchors name a path under the first shape. Counted with
`os.path.exists()`, every one of them is dangling.

**That count is wrong, and the way it is wrong is the point of this change.**

Gate 46, `spec-anchor-existence`, does not resolve anchors by literal path. It
builds a capability index spanning all three homes a spec has in its life:

1. in flight, `openspec/changes/<change>/specs/<cap>/spec.md`
2. archived, `openspec/changes/archive/<date>-<change>/specs/<cap>/spec.md`
3. canonical, `openspec/specs/<cap>/spec.md`

Run over the whole tree, the gate's own resolver reports **zero** unresolved
targets across 1,698 files. The resolver was mutation-checked first: two planted
anchors, one naming a change that does not exist and one naming a capability
that does not exist, are both reported. So the zero is a measurement, not a
silence.

Six anchors are genuinely dangling, and they were found by widening the check
rather than by narrowing it. All six are `@e2e`, and **gate 46 reads only
`@spec`**: its pattern is `@spec\s+(openspec/...)`. All 286 `@e2e` anchors in
this repo are unchecked by any gate, which is why these six survived.

| Anchor | Capability | Homes |
|---|---|---|
| `workflow-board` (2) | none | 0 |
| `bezwaar-management` (2) | none | 0 |
| `case-map` | none | 0 |
| `subsidy-intake` | none | 0 |

## What changes

Nothing sweeps. 6,244 anchors stay exactly as they are, because they resolve.

- **The six dangling `@e2e` anchors are repointed** at capabilities that exist:
  `dashboard#REQ-DASH-V1-006`, `bezwaar-lifecycle`, `case-map-overview#REQ-OVERVIEW-01`
  and `subsidieverlening-keten`.
- **The convention is written down** as a capability of its own, so the next
  person inherits the reasoning rather than re-deriving it from a wrong count.

## The convention, and why it is not a path rule

An anchor names **the capability**, in its canonical spelling
`openspec/specs/<cap>/spec.md`. It is written once and never rewritten.

The tempting alternative is to make anchors track wherever the spec currently
lives, rewriting them on archive. That converts every archive into a second
breakage of the same kind, and it is self-defeating at the scale this repo
already has: 107 anchors name `analytics-dashboard-surface`, whose only home
today is the open change `page-topology-cleanup`. Under a path rule those 107
break the day that change lands. Under the capability rule they all become
correct the same day, with no edit.

## The case the rule has to answer: a capability with no canonical spec yet

This is the case a naive reading of "point at `openspec/specs/`" gets wrong, so
it is stated explicitly.

When the governing requirement lives in a change that has not been archived,
the anchor **still names `openspec/specs/<cap>/spec.md`**, even though that file
does not exist yet. The anchor is not wrong; it is early. Archive is the moment
the content arrives at the path the anchor already named.

That is safe only because resolution is by capability across three homes. The
anchor resolves today through home (1), the open change's delta, and after
archive through (2) and (3). One spelling, correct at every stage.

286 anchors in this repo are in exactly that state right now, across 11
capabilities, and all 286 resolve.

## Not in this change

- **Gate 46 does not read `@e2e`, and does not enumerate `appinfo/` or
  `scripts/`** (2 anchors live there). Both are upstream in
  `ConductionNL/.github`, not fixable here. Raised separately so the next repo
  to hit this does not have to re-measure it.
- **No anchor rewrite.** The sweep this change was opened to scope does not
  exist. Saying so is the deliverable.
