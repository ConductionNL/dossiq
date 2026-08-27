---
id: master-data-stewardship
title: Master data stewardship (MDM via OpenRegister)
sidebar_position: 2
description: How data stewards govern Dossiq's duplicate-prone data (cases, suppliers, partner organisations) through OpenRegister's MDM surface. Dossiq ships no MDM UI of its own.
---

# Master data stewardship

Dossiq consumes OpenRegister's master-data-management engine (ADR-045: OpenRegister owns the
MDM surface; ADR-022: apps consume OR abstractions). Dossiq **declares** data-quality and
duplicate-detection rules on its schemas; OpenRegister **executes** them and renders the steward
surface. Dossiq contains no duplicate matching, merging, scoring, or steward views of its own,
and adds no MDM pages or navigation entries.

## Annotated schemas

The rules live on the schema definitions in `lib/Settings/dossiq_register.json` and travel with
the normal register import (repair step). Requires OpenRegister >= 0.2.16.

| Schema | Quality rules | Duplicate detection |
|--------|---------------|---------------------|
| `case` (zaak) | required `title`, `caseType`, `identifier`; freshness on `startDate` (half-life 365 d) | blocking on `caseType`; exact `identifier` (0.4), exact `vergunningaanvraagRef` (0.3, DSO double-intake guard), normalized + levenshtein `title` |
| `supplier` | required `legalName`, `kvkNumber`; `kvkNumber` format `^[0-9]{8}$` | exact `kvkNumber` (0.4), exact `iban` (0.3), normalized + levenshtein `legalName` |
| `partnerOrganization` | required `name`, `oin`; `contactEmail` email format | exact `oin` (0.5), normalized + levenshtein `name` |

All dedup rule sets use the fleet-default candidate threshold `0.7` and quality thresholds
`good >= 0.8` / `fair >= 0.5` (steward-tunable in OpenRegister afterwards). On every save,
OpenRegister materialises `qualityScore` (0–1) and `qualityStatus` (`good`/`fair`/`poor`) on the
object; both fields are facetable in list views.

## Steward workflow

1. Open **OpenRegister** and navigate to the governance views (Data Quality, Duplicate
   Candidates, Master entities) — see OpenRegister's own documentation for the exact navigation.
2. Scope the view to the **dossiq** register.
3. Review duplicate-candidate pairs (e.g. two cases sharing a `vergunningaanvraagRef`, two
   suppliers sharing a `kvkNumber`). Dossiq never blocks or auto-merges — candidates are
   surfaced for human review.
4. Merge in OpenRegister where appropriate. OR merges are reversible and UUID-stable: dossiq's
   views show the surviving object without any dossiq-side change, because dossiq reads all
   objects through OR's API.
5. Quality scores recalculate on the next save of each object; a steward can trigger
   recalculation from OR's views. Dossiq ships no backfill job.

## Explicit non-adoption: survivorship

Dossiq declares **no** `x-openregister-survivorship`. Survivorship resolves a golden record from
trust-tiered *source records* linked to a master entity; every annotated dossiq entity is
single-source today (dossiq's register is the one system of record), so a survivorship
declaration would make OR's materialiser a no-op.

**Revisit trigger:** when live BRP/KvK feeds (see the `external-integrations-test-environments`
change) start delivering initiator data alongside manual entry, an initiator master with
source records appears and survivorship is declared then.
