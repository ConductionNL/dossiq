---
kind: config
depends_on: []
chain: []
---

# Proposal: add-procest-procurement-suite

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Why

Procest is the case-management foundation for Conduction (zaakgericht
werken on Nextcloud + OpenRegister). It already ships robust
public-sector case patterns (besluitvorming, bezwaar-beroep,
parafering, VTH, handhaving) but lacks an explicit, consolidated
description of the **procurement, contracting, supplier, and tender**
surface that municipal and SMB operators expect when they handle
public procurement as cases.

Specter's intelligence pipeline (`specter_worker.py`, the
`app_specs` table) discovered 26 procurement-adjacent draft specs
under the procest namespace, originally drafted while the work was
parked under the now-deprecated `budgetq` app. Each spec carries
a misleading `— Shillinq` title suffix from that earlier shape;
their content however describes a public-procurement workflow that
fits procest's case-management framing, not shillinq's bookkeeping
engine.

Left as 26 separate specs, the surface is:

- impossible to review as a coherent product (each draft duplicates
  the same Nextcloud/OpenRegister boilerplate),
- mis-titled with the Shillinq suffix,
- structurally inconsistent — some are pure feature lists, some are
  near-empty stubs, none follow procest's case-centric framing.

This change consolidates the 26 drafts into **8 capability specs**
under the `add-procest-procurement-suite` envelope. Each consolidated
spec frames its register(s) as `schema:Project` cases (supplier-as-
case, contract-as-case, tender-as-case) and is anchored to OR
abstractions per ADR-022 / ADR-031 — no parallel storage, no custom
state machines, no custom audit tables.

## What changes

1. **New capability specs (8)**, each shipped as a delta under this
   change's `specs/` directory:

   | # | Slug | REQ prefix | Source drafts consolidated |
   |---|---|---|---|
   | 1 | `procest-procurement-supplier-management` | SUP | `supplier-management`, `supplier-management-ai`, `supplier-management-misc`, `supplier-management-other-t1..t5`, `supplier-performance-management` |
   | 2 | `procest-procurement-contract-lifecycle` | CLM | `contract-lifecycle-management`, `-ai`, `-analytics`, `-document-management`, `-other-t1..t4` |
   | 3 | `procest-procurement-system-integration` | PSI | `procurement-integration`, `procurement-integration-integration`, `procurement-integration-other-t1..t3` |
   | 4 | `procest-procurement-tender-management` | TND | `tender-management` |
   | 5 | `procest-procurement-evaluation-award` | EVA | `evaluation-award` |
   | 6 | `procest-procurement-compliance` | PCC | `procurement-compliance` |
   | 7 | `procest-procurement-publication-platform` | PPP | `publication-platform-integration` |
   | 8 | `procest-procurement-spend-analytics-integration` | PSA | (cross-app contract, no consolidated drafts) |

2. **Tier label**: every spec carries `Tier: procurement-suite` (procest
   has no numeric tier roadmap; this label is the suite anchor).

3. **No code, no UI, no controllers, no tests** are added by this
   change. It is a *declarative* `kind: config` change per ADR-032 —
   spec deltas + register-shape implications only. Implementation
   lands in chained code specs once the suite specs merge.

4. **Cross-app dependencies declared but not introduced**:
   - `openconnector` for all external transport (TenderNed, Mercell,
     Negometrix, Peppol/GHX, TED/OJEU, Digipoort SBR, RGS, etc.).
   - `docudesk` for contract documents, signed PDFs, attachments.
   - `openregister` for RBAC, audit, retention, lifecycle,
     aggregations, scheduled workflows.
   - `mydash` for the analytics surface — procest emits events, mydash
     reads via runtime GraphQL (per ADR-024 §10 and
     `feedback_mydash-no-or-dependency.md`).
   - `financeq` — `[future]` reference only; the repo does not yet
     exist. Spend / cost integration is documented as a placeholder.

## Impact

- **Specs added:** 8 new capability specs under
  `procest/openspec/specs/` once this change archives.
- **Code changed:** none in this change. Each spec's implementation
  is the work of a follow-up code chain (per ADR-032) — typically:
  (1) register-patch landing the schema, (2) manifest entry landing
  the navigation, (3) integration smoke test, (4) optional UI
  decoration on top of the generic page renderer.
- **Drafts to archive (26)** in the `app_specs` intelligence table
  once this change merges. See "Source draft reconciliation" in
  `design.md`.
- **No breaking changes** — procest's existing case-management,
  case-types, workflow-engine-abstraction specs continue to operate
  unchanged. The new specs sit beside them as additional capability
  surfaces consumed by the same case-management plumbing.

## Out of scope

- PHP / Vue implementation code (deferred to per-spec code chains).
- UI/component design beyond manifest navigation entries
  (manifest-driven per ADR-024 — generic renderers).
- Tests, CI, fixtures.
- Deep `financeq` integration (no repo, marked `[future]`).
- Auto-merging of the 26 source drafts in Specter — that is an
  intelligence-DB housekeeping step run after this change archives.
- Belgian Federal e-Procurement (Free Market) and Spanish PLACSP
  source registrations — listed as connector slots in spec #3
  but their OpenConnector source rows land in a separate
  `add-openconnector-eu-procurement-sources` change.

## Reviewer gates this change should pass

- ADR-022: no parallel storage scenarios — every spec includes the
  "reviewer scans for `lib/Db/{*}_mapper.php`" pattern.
- ADR-031: no custom state-machine services — every lifecycle declared
  as `x-openregister-lifecycle`.
- ADR-024: every spec ends with a manifest-navigation requirement.
- ADR-032: this is `kind: config` (specs only — no code surface).
- Procest case-centric framing: every register that represents work
  (supplier-as-case, contract-as-case, tender-as-case) is reachable
  from `case-management`'s `caseType` machinery so existing
  dashboards, my-work, doorlooptijd, role-routing already work
  without per-capability code.
