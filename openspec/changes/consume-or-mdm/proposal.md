# Proposal: consume-or-mdm

kind: adoption / abstraction consumption — cites **ADR-045** (openregister-owns-mdm-surface) and
**ADR-022** (apps-consume-or-abstractions). Product-owner decision 2026-07-05: procest MDM lives in
OpenRegister; procest defers.

## Why

Procest has **zero** MDM adoption today: no `x-openregister-quality`, `x-openregister-dedup`, or
`x-openregister-survivorship` annotation exists anywhere under `lib/Settings/` (verified 2026-07-05
against the working tree — `grep -r` over `lib/Settings/procest_register.json` + `register.d/`
returns nothing). Meanwhile OR's MDM engine (quality scoring, duplicate detection, on-save
materialisation of `qualityScore`/`qualityStatus`) and the generic steward surface are owned by
OpenRegister per ADR-045; pipelinq is the canonical consumer
(`../pipelinq/lib/Settings/register.d/90-master-data-management.json` carries the annotations, and
its `src/manifest.d/90-master-data-management.json` is intentionally empty — pipelinq ships no MDM
UI of its own).

Procest carries real duplicate risk on a handful of schemas and it is about to get worse:
zaakportaal intake (`portaalVerzoek`), KCC intake (`customerContact`), DSO intake
(`DsoIntakeService` creates permit cases from vergunningaanvraag messages), and — with the
in-flight `semantic-case-intake` change — pipelinq handoff will all create cases through different
funnels. Supplier and partner-organisation masters (`supplier` with `kvkNumber`,
`partnerOrganization` with `oin`) are classic org-dedup territory. Without declared rules, OR's
governance surface has nothing to show stewards for procest data.

## What Changes

Annotate the schemas where duplicates matter most, directly where those schemas live
(`lib/Settings/procest_register.json` — annotating in-place avoids the register.d union-merge
pitfalls for pre-existing schemas):

1. **`case` (zaak)** — the multi-funnel intake entity.
   - `x-openregister-quality`: required `title`, required `caseType`, required `identifier`,
     freshness on case activity.
   - `x-openregister-dedup`: exact match on `identifier`, exact on `vergunningaanvraagRef`
     (DSO double-intake), normalized `title` + exact `caseType` within an intake window.
   - No `x-openregister-survivorship`: a case has exactly one system of record (procest's
     register); there are no trust-tiered source records to resolve a golden record from.
2. **`supplier`** — org master with `kvkNumber`, `legalName`, `iban` (verified in
   `procest_register.json`). Mirror pipelinq's masterEntity rules: exact `kvkNumber` (0.4),
   exact `iban`, normalized + levenshtein `legalName`; quality rules on required
   `kvkNumber`/`legalName` + `^[0-9]{8}$` format.
   - Note: ADR-048's context records that a thin duplicate supplier schema was *removed* from
     pipelinq in favour of shillinq's Payee. Whether procest's `supplier` should eventually become
     a semantic reference to that master is out of scope here (see Out of Scope); annotating it
     now still protects the data procest actually holds.
3. **`partnerOrganization`** — case-transfer partner master (`name`, `slug`, `oin`): exact `oin`,
   normalized `name`; quality on required `oin` + `contactEmail` format.

And the consumption rules:

4. **No app-local dedup/merge/quality code** — procest writes no scoring, matching, merge, or
   steward views. Stewards use OR's Data Quality / Duplicate Candidates / Master-entity views
   (ADR-045 surface), scoped to procest's register.
5. **No procest MDM UI or nav** — mirroring pipelinq's empty
   `src/manifest.d/90-master-data-management.json`, procest adds no MDM pages. Since procest never
   had any, this is a documented non-addition, not a removal; no manifest fragment is needed.

## Schemas considered and rejected

- **`role` (betrokkene)** — its `participant` is a plain string ("Nextcloud user ID or contact
  reference", verified); person identity lives in NC accounts/contacts and, for aanvragers, in the
  BRP/KvK source registers of `brp-kvk-register-sets`. Authoritative source registries are not
  MDM-deduped (they are the survivorship *source*, not the master under governance).
- **KCC `customerContact` (register.d/30-kcc.json)** — a contact *moment* (event), not an entity;
  deduping events would be wrong. Its `customerName/Phone/Email` capture quality belongs to a
  future contact-master decision (see Out of Scope).
- **`tenant`** — carries `kvkNumber` but is admin-curated and low-volume; a duplicate tenant is an
  operational error surfaced elsewhere, not a stewardship problem.

## Impact

- `lib/Settings/procest_register.json` — annotations on `case`, `supplier`, `partnerOrganization`.
- No PHP, no Vue, no routes. OR renders the governance surface.
- Depends on the deployed OR version shipping the MDM engine (ADR-045 references OR change
  `2026-06-23-mdm-foundation`; the annotations are read by OR's quality/dedup services).

## Out of Scope

- Survivorship/trust-tier configuration — procest has no multi-source golden-record entity today.
  If BRP/KvK live feeds (see `external-integrations-test-environments`) later feed an
  initiator master, survivorship is declared then.
- Re-pointing `supplier` at a cross-app procurement master via ADR-048 semantic references —
  separate decision with shillinq.
- Any change to OR's MDM engine or steward views (OR-owned per ADR-045).
