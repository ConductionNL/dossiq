# Proposal: spec-anchors-name-the-capability

## Why

Archiving 46 changes on 2026-09-09 looked like it had broken thousands of `@spec`
and `@e2e` anchors. A change directory moves on archive, from
`openspec/changes/<name>/` to `openspec/changes/archive/<date>-<name>/`, and
2,955 anchors name a path under the first shape. Counted with
`os.path.exists()`, every one of them is dangling. I reported roughly 420, then
re-measured and got 2,955.

**Both numbers are wrong, and wrong the same way.** Gate 46,
`spec-anchor-existence`, does not resolve anchors by literal path. It builds a
capability index spanning the three homes a spec has in its life:

1. in flight, `openspec/changes/<change>/specs/<cap>/spec.md`
2. archived, `openspec/changes/archive/<date>-<change>/specs/<cap>/spec.md`
3. canonical, `openspec/specs/<cap>/spec.md`

Keyed on the capability, an anchor written once keeps resolving through the
archive move and the promotion after it. Run over all 1,698 files in scope, the
gate's own resolver reports **zero** unresolved targets.

The gate's docblock anticipates this mistake under a heading reading "WHAT THIS
IS NOT": a prior issue reported 353 `tasks.md` targets as never
existence-checked, and the correction is that they all resolve through the
archive index. I reproduced that issue's error before reading its correction.

The zero was mutation-checked before it was believed. Two anchors were planted,
one naming a change that does not exist and one a capability that does not, and
both were reported. An instrument that cannot fail does not get to say zero.

## What this change is now

**Only the convention.** The six genuinely dangling anchors this investigation
found were fixed while it was running, by #2067, and that fix is better than the
one this branch originally carried. See "What a parallel session got right"
below. Those edits are dropped; this change is the write-up and the rule.

## The convention

An anchor names **the capability**, in its canonical spelling
`openspec/specs/<cap>/spec.md`. It is written once and never rewritten.

The tempting alternative is to make anchors track wherever the spec currently
lives, rewriting them on archive. That turns every archive into a fresh
breakage, and it is self-defeating at the scale this repo already has: 107
anchors name `analytics-dashboard-surface`, whose only home today is the open
change `page-topology-cleanup`. Under a path rule those 107 break the day that
change lands. Under the capability rule they all become correct that day, with
no edit.

That a change-directory citation resolves does not make it right. It names no
capability, so nothing can carry it forward on its own; the archive index
rescues it by change name, which is a different and weaker guarantee. #2063
repointed 55 such citations, and this is why that was correct work even though
nothing was failing.

## The case the rule has to answer: a capability with no canonical spec yet

When the governing requirement lives in a change that has not been archived, the
anchor **still names `openspec/specs/<cap>/spec.md`**, even though that file does
not exist yet. The anchor is not wrong; it is early. Archiving is the moment the
content arrives at the path the anchor already named.

That is safe only because resolution is by capability across three homes. The
anchor resolves today through home (1), the open change's delta, and after
archive through (2) and (3). One spelling, correct at every stage.

286 anchors here are in exactly that state, across 11 capabilities, and all 286
resolve.

## What a parallel session got right, and this one did not

#2067 landed the same six anchors first and better, on both counts that matter.

- It anchored at **scenario** headings (`scenario-dash-v1-006a-board-columns-reflect-status-types`)
  rather than at the requirement. A test proves a scenario, so that is the
  tighter and truer link.
- For the two anchors with no home anywhere, it used **`@e2e exclude` with a
  reason**. This branch had pointed them at the nearest plausible capability
  instead. That resolves, and it is worse than not resolving: it claims a
  coverage relationship that does not exist. A citation that resolves while
  naming the wrong thing is exactly the failure this whole area is about.

The convention below adopts that rule rather than the one this branch started
with.

The process lesson is the older one and it is on me: other sessions work the
same backlog, so `git log origin/development -5 -- <path>` comes before building,
not after. This branch went out CONFLICTING with 4 of 49 checks green, which is
the shape a conflicting PR always has.

## The correction this change had to make to itself

The first version of this proposal claimed 6,244 anchors and zero unresolvable.
That was measured with a REIMPLEMENTATION of the resolver rather than the
resolver, and it was wrong in both directions.

Mine ignored two things the real one does. The flat `openspec/specs/<cap>.md`
spelling, which predates the directory form: that alone made it call 13 of
planix's healthy anchors dangling. And fragment checking: the real helper
verifies that a `#fragment` names a heading somebody wrote, and mine only
checked the file.

Run properly, this repo has **28 unresolved `@e2e` anchors**, every one of them
"anchor not found". Two of the 28 were broken by this session's own #2057, which
renumbered two scenarios from `DASH-V1-006d/e` to `006f/g` to clear a collision
and left two `@e2e` citations naming the old ids. Every check passed. Those two
are repointed here; the other 26 are pre-existing and are the debt the gate fix
in "Not in this change" would surface.

The lesson is the one this whole investigation keeps teaching: every wrong
number came from a hand-rolled resolver, and every right one came from running
`check_spec_anchors.py`. `@e2e` was eventually measured by rewriting the tags to
`@spec` in a probe file OUTSIDE the repo and running the real helper with cwd set
to the app, which is exact and touches nothing.

## Not in this change

- **Gate 46 does not read `@e2e`.** Its pattern is `@spec\s+(openspec/...)`, so
  all 286 `@e2e` anchors here are checked by nothing. That is why the six rotted
  unseen, and why they were found by hand twice rather than by CI once.
- **Gate 46 enumerates `lib src tests`.** Two anchors under `appinfo/` and
  `scripts/` are never opened.

Both live in `ConductionNL/.github`, `hydra-gates/scripts/lib/check_spec_anchors.py`,
and neither is fixable here. Filed as ConductionNL/.github#726 (1,964 `@e2e`
anchors fleet-wide, 194 dangling) and #727 (52 anchors under `appinfo/` and
`scripts/` never opened, 8 of openregister's 9 in `routes.php` dangling).
