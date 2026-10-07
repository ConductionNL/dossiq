---
kind: code
depends_on: []
---

# Proposal: deelzaken-inherit-the-parent-grants

Competitor gap register, row Q13.23 "Does a right granted on a parent
apply to its descendants without a second grant"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner openregister, slug
`rbac-inherits-to-children`, size M. This change is dossiq's half: the
declaration on the case schema, and deleting the answer `CaseAccessGuard`
keeps for itself.

## Why

Someone is given access to a case and cannot open its deelzaken. Somebody
then grants those separately, and the two grants drift: the deelzaak stays
open to a person who was removed from the parent a year ago.

The register's note: "row 2.10 records sub-cases and 13.3 records partner
shares per case, and `deelzaak|parentCase|subCase` over
`lib/Service/CaseAccessGuard.php` returns 0, so nothing in the guard reads
the parent. Vikunja resolves access through a recursive query over the
parent chain, measured: read on the root reached the child and the
grandchild, write refused on both. Kanboard's projects do not nest."

The best competitor, verbatim from the register's `best` column: "Vikunja
2.6.0: project_access.go:48-71 resolves access through a recursive CTE
over parent_project_id; read on the root read the grandchild and was
refused a write, measured
(`_round4/compare/proposed-rows-batch7.md`)".

`case.parentCase` already exists on the schema, a uuid referencing `case`,
described as "Reference to parent case (for sub-cases)". The edge is
there; nothing reads it for access.

## What changes

- The case schema declares `parentCase` as its hierarchy edge, so a grant
  on a case reaches its deelzaken and their deelzaken.
- `CaseAccessGuard` stops keeping its own answer about who may read or
  mutate a case and asks the platform, which is where the inherited grant
  is resolved. The guard keeps the dossiq-specific rules that are not
  access inheritance.
- The case page says where access came from: a deelzaak opened through the
  parent shows which case granted it.
- The Sharing tab of a parent says that a share reaches its deelzaken, so
  nobody shares a parent expecting the children to stay private.

## Ownership

dossiq declares the edge, deletes its own resolution and renders the
provenance. It consumes openregister `rbac-inherits-to-children`, to be
specified in openregister under that slug (row Q13.23): the
`x-openregister-hierarchy` annotation, the recursive resolution on the
object and the list path, the depth cap and the cycle guard, and the
provenance in the scope audit. The register's `dossiq_half`: "deelzaken
inherit the parent case's grants; CaseAccessGuard stops keeping its own
answer".

## ADRs

- Company ADR-022: access inheritance is the authorization layer's; dossiq
  keeps no parallel guard.
- Company ADR-005: two guards answering the same question is the failure
  mode; the app-side answer goes away in the same step the platform's
  arrives.
- Company ADR-048: the parent edge is a declared semantic reference, not a
  string somebody parses.

## Capabilities

- Modified: `deelzaak-support`: a deelzaak inherits its parent's grants.

## Impact

`lib/Settings/dossiq_register.json` (the hierarchy annotation on `case`),
`lib/Service/CaseAccessGuard.php` (the read and mutation answers),
`src/manifest.json` (`#CaseDetail` provenance, the Sharing tab note),
`tests/Unit/Service/CaseAccessGuardTest.php`, one e2e spec.

## Out of scope

- Blocking inheritance on one deelzaak. The platform does not offer it and
  no competitor in the register has it.
- Related cases. `relatedCases` is a link, not a hierarchy, and inheriting
  through it would make access travel sideways without limit.
