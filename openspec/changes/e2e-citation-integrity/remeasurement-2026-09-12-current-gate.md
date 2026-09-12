# Re-measurement, 2026-09-12, against the current gate

Re-ran `extract-citations.py` over `development` plus the branches merged so
far today. Data: `audit-2026-09-12-current-gate.csv`.

## Read this first: the gate you run is part of the measurement

The first run of this re-measurement reported **181 credited and 23 citations
whose anchor matched no scenario**, and read as a regression of 23 against the
09-12 baseline. It was not a regression. It was the wrong gate.

The `.github` submodule checked out in this workspace sits at 2026-09-08 and is
**64 commits behind `origin/main`**. Its `check_e2e_coverage.py` contains no
`github_anchor_ref`, the function that accepts GitHub's `scenario-` prefixed
anchor form. Every citation using that form failed to resolve, and the
extractor's own pre-#753 fallback shim quietly took over.

Same tree, same extractor, current gate: **206 credited and 4 unresolved
anchors**. The 23 were an artifact.

So: before quoting a coverage number, print the gate's provenance. Extract the
current one without disturbing the shared submodule:

```sh
git -C .github fetch origin main
git -C .github archive origin/main hydra-gates/scripts/lib | tar -x -C /tmp/gate-current
PYTHONPATH=/tmp/gate-current/hydra-gates/scripts/lib \
  python3 openspec/changes/e2e-citation-integrity/extract-citations.py \
  /tmp/gate-current/hydra-gates/scripts/lib/check_e2e_coverage.py . out.csv
```

## Quote distinct scenarios, not citations

Every number below is a CITATION count, and a citation count reads high. On
`development` at the time of writing, **307 citations resolve onto 168 distinct
scenarios credited**. Two citations repaired on one scenario move coverage
once, not twice, so a citation total overstates what a repair bought.

This is task 5.5 of this change, and it applies to this document as much as to
any dashboard. Where a number is meant to mean coverage, use the distinct
scenario count; where it is meant to mean how much citation work is left, the
citation count is the right one. Say which you are quoting.

## Where the population stands

308 citations. 206 credited by gate-19, 102 not.

| why a citation credits nothing | count |
|---|---|
| no anchor: cites a whole spec file | 73 |
| cites `openspec/changes/**`, which gate-19 does not parse | 21 |
| the test does not run | 4 |
| the anchor matches no scenario in that spec | 4 |
| no anchor, and the file does not exist | 1 |

## The biggest bucket is anchorless citations, not weak ones

**73 citations name a spec file and no requirement at all.** That is a larger
and more concentrated population than the 80 the 09-12 worklist lists as not
verified, and it is a different kind of problem: these tests may well prove
something, but the citation never says what, so gate-19 credits them nothing
and no reader can check the claim.

| file | anchorless |
|---|---|
| `tests/e2e/case-list-lenses.spec.ts` | 23 |
| `tests/e2e/case-requester.spec.ts` | 10 |
| `tests/e2e/case-type-authoring-extras.spec.ts` | 10 |
| `tests/e2e/case-type-edit-and-setup.spec.ts` | 9 |
| `tests/e2e/case-identity.spec.ts` | 7 |
| `tests/e2e/spec-coverage/work-navigation.spec.ts` | 4 |
| `tests/e2e/spec-coverage/store-surface.spec.ts` | 3 |
| six other files | 7 |

The specs cited without an anchor most often are `case-management` (10),
`first-time-setup` (8), `my-work` (7), `task-management` (7) and
`initiator-display` (7).

## How to close one, and how not to

Adding an anchor is only a repair if the test proves that scenario. An anchor
chosen to satisfy the gate is worse than none: a dangling citation is visibly
broken, while a citation resolving to an unrelated scenario reads as coverage
and survives review.

Where the right scenario does not exist, the honest outcomes are to write it,
or to take the citation down. Both are better than a plausible-looking anchor.

## The worklist is stale, and that costs real time

Of five files taken from the 09-12 worklist for one block of work, **three were
already fully repaired on `development`**, one of them carrying a documented
mutation check in the file. Read the current file before repairing what the
worklist describes. A file carrying a 🔴 or ✅ comment explaining its repair is
done.
