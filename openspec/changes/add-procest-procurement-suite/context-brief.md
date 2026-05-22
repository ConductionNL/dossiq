---
kind: config
depends_on: []
chain: []
---

# Proposal: add-procest-procurement-suite

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** (deferred) / —

**Rationale:** Aparte suite, naar fase 3 of eigen app.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

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



## Design

# Design: add-procest-procurement-suite

## Domain framing — procurement as case management

Procest models everything as a **case** (`schema:Project`,
`case-management` capability). The procurement suite preserves that
framing rather than introducing a new top-level domain object:

| Procurement concept | Procest framing | Existing procest capability consumed |
|---|---|---|
| Supplier (vendor) | Supplier-as-record + Supplier-onboarding-as-case (one case per onboarding/qualification cycle) | `case-management`, `case-types`, `roles-decisions` |
| Contract | Contract-as-record + Contract-lifecycle-as-case (one case per material event: signature, renewal, amendment, termination) | `case-management`, `case-types`, `workflow-engine-abstraction`, `parafering-actions` (signatures) |
| Tender (aanbestedingsdossier) | Tender-as-case (`schema:Project`) with sub-cases per lot, per round, per RFI/RFP/RFQ phase | `case-management`, `case-types`, `deelzaak-support`, `process-step-configuration` |
| Evaluation | Evaluation-as-record attached to a tender case; scoring matrix as `propertyDefinition` data | `case-management`, `roles-decisions` |
| Award | Award-as-decision on a tender case — fits procest's existing `decision` register exactly | `case-management`, `roles-decisions`, `besluitvorming-workflow` |

Three principles flow from this framing:

1. **No new workflow engine.** ADR-022 forbids a parallel mechanism;
   procest already wraps OR's workflow engine in
   `workflow-engine-abstraction`. Every procurement lifecycle declared
   in the suite is an `x-openregister-lifecycle` block on its schema
   per ADR-031. No `SupplierOnboardingService::transition()` PHP class
   gets written.
2. **No new audit/RBAC system.** All registers are OR-backed; RBAC
   comes from OR's per-schema permissions; audit from
   `audit-trail-immutable`. The "supplier user can edit their own
   profile" pattern reuses the same RBAC model `case-management`
   already uses.
3. **No CoA / GL in procest.** Spend, cost, GL postings, invoices —
   procest emits domain events; consumers (mydash via GraphQL,
   `[future]` financeq via OpenConnector source) compute the money
   side. Procest never owns a chart-of-accounts or a posting table.

## How the 8 specs fit together

```
                ┌─────────────────────────────────────────────────┐
                │ procest case-management (existing)              │
                │ case, caseType, statusType, role, decision      │
                └────────────────┬────────────────────────────────┘
                                 │ all 8 specs consume
                ┌────────────────┼────────────────┐
                ▼                ▼                ▼
        ┌───────────────┐ ┌───────────────┐ ┌───────────────┐
        │ SUP supplier  │ │ CLM contract  │ │ TND tender    │
        │ registers +   │ │ registers +   │ │ registers +   │
        │ onboarding    │ │ lifecycle     │ │ deelzaak per  │
        │ case-type     │ │ case-type     │ │ lot/round     │
        └───────┬───────┘ └───────┬───────┘ └───────┬───────┘
                │                 │                 │
                ▼                 ▼                 ▼
        ┌───────────────┐ ┌───────────────┐ ┌───────────────┐
        │ EVA scoring   │ │ PCC compliance│ │ PSI external  │
        │ on tender     │ │ thresholds +  │ │ connectors    │
        │ cases         │ │ UEA/EML       │ │ via openconn. │
        └───────┬───────┘ └───────┬───────┘ └───────┬───────┘
                │                 │                 │
                └────────┬────────┴─────────────────┘
                         ▼
                ┌─────────────────────────┐
                │ PPP publication-platform│
                │ (TED/OJEU/national bekend-│
                │ makingen + amendment    │
                │ re-publish flagging)    │
                └─────────────────────────┘
                         │
                         ▼
                ┌─────────────────────────┐
                │ PSA spend-analytics     │
                │ events → mydash         │
                │ (cross-app contract)    │
                └─────────────────────────┘
```

Spec ordering for downstream code chains (per ADR-032):

1. **First wave** (independent, can chain in parallel): SUP, CLM, TND
   — each adds a `caseType` seed + a small set of new schemas.
2. **Second wave** (depends on first): EVA (needs TND), PCC (needs
   SUP + TND + CLM for cross-register policy checks).
3. **Third wave** (depends on second): PSI (the connector slots are
   declared once the data shapes are stable), PPP (depends on TND +
   EVA + PSI).
4. **Fourth wave** (cross-app contract): PSA — emits events from all
   of the above; ships when at least one of TND/CLM is in code.

## OR abstraction usage table

Per ADR-022, every spec declares which OR abstractions it consumes
and which it does NOT reimplement.

| Abstraction | SUP | CLM | TND | EVA | PCC | PSI | PPP | PSA |
|---|---|---|---|---|---|---|---|---|
| Registers + schemas + objects | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| RBAC (authorization) | ✓ | ✓ | ✓ | ✓ | ✓ | – | – | – |
| Audit trail (immutable) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Archival + destruction (retention) | ✓ | ✓ | ✓ | – | – | – | – | – |
| `x-openregister-lifecycle` | ✓ | ✓ | ✓ | ✓ | – | – | ✓ | – |
| `x-openregister-aggregations` | ✓ | ✓ | ✓ | ✓ | ✓ | – | – | ✓ |
| `x-openregister-calculations` | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | – |
| `x-openregister-notifications` | ✓ | ✓ | ✓ | – | ✓ | – | ✓ | – |
| `x-openregister-relations` | ✓ | ✓ | ✓ | ✓ | ✓ | – | – | – |
| `x-openregister-widgets` | – | ✓ | ✓ | ✓ | ✓ | – | – | ✓ |
| Integration registry (ADR-019) — providers | – | – | – | – | – | ✓ | ✓ | – |
| OR `ScheduledWorkflow` + n8n | – | ✓ | – | – | ✓ | ✓ | ✓ | – |
| Deep link registry | ✓ | ✓ | ✓ | – | – | – | – | – |
| Events + webhooks (CloudEvents) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

## Declarative-vs-imperative decision (ADR-031)

Every behaviour described in the 8 specs has been classified:

- **Declarative (default)** — lifecycles, aggregations, calculations,
  notifications, relations, widgets. Lands as JSON patches on
  `lib/Settings/procest_register.json`. Reviewer should reject any
  follow-up code chain that authors a `*Service::transition*`,
  `*Service::getSummary*`, `*Service::compute*Field*`, or
  `*Service::notifyOn*` for a register declared in this suite.
- **Imperative (justified)** — only:
  - the OpenConnector source rows that talk to TenderNed, Mercell,
    Negometrix, Peppol, RGS, TED, Digipoort SBR (PSI + PPP). These
    are connector definitions, NOT services in procest's `lib/`.
  - the lifecycle guards (`x-openregister-lifecycle.requires`)
    called by the declarative engine for non-trivial preconditions
    (e.g. "tender award requires standstill period elapsed",
    "contract renewal requires no open termination case"). Each guard
    is a short, single-method PHP class.
- **Schema engine gap** — none observed. Every behaviour fits an
  existing `x-openregister-*` extension. If a future spec discovers a
  gap, it opens an OR issue and adds a guard as a temporary bridge per
  ADR-031 exception (1).

## Source draft reconciliation (intelligence-db cleanup)

After this change archives, the following 26 rows in
`app_specs` (where `app_slug = 'procest'`) MUST be marked
`status = 'superseded'` with `superseded_by =
'add-procest-procurement-suite'` to prevent Specter from re-emitting
them as fresh issues:

| Draft slug | Consolidated into |
|---|---|
| `supplier-management` | `procest-procurement-supplier-management` |
| `supplier-management-ai` | `procest-procurement-supplier-management` |
| `supplier-management-misc` | `procest-procurement-supplier-management` |
| `supplier-management-other-t1` | `procest-procurement-supplier-management` |
| `supplier-management-other-t2` | `procest-procurement-supplier-management` |
| `supplier-management-other-t3` | `procest-procurement-supplier-management` |
| `supplier-management-other-t4` | `procest-procurement-supplier-management` |
| `supplier-management-other-t5` | `procest-procurement-supplier-management` |
| `supplier-performance-management` | `procest-procurement-supplier-management` |
| `contract-lifecycle-management` | `procest-procurement-contract-lifecycle` |
| `contract-lifecycle-management-ai` | `procest-procurement-contract-lifecycle` |
| `contract-lifecycle-management-analytics` | `procest-procurement-contract-lifecycle` |
| `contract-lifecycle-management-document-management` | `procest-procurement-contract-lifecycle` |
| `contract-lifecycle-management-other-t1` | `procest-procurement-contract-lifecycle` |
| `contract-lifecycle-management-other-t2` | `procest-procurement-contract-lifecycle` |
| `contract-lifecycle-management-other-t3` | `procest-procurement-contract-lifecycle` |
| `contract-lifecycle-management-other-t4` | `procest-procurement-contract-lifecycle` |
| `procurement-integration` | `procest-procurement-system-integration` |
| `procurement-integration-integration` | `procest-procurement-system-integration` |
| `procurement-integration-other-t1` | `procest-procurement-system-integration` |
| `procurement-integration-other-t2` | `procest-procurement-system-integration` |
| `procurement-integration-other-t3` | `procest-procurement-system-integration` |
| `tender-management` | `procest-procurement-tender-management` |
| `evaluation-award` | `procest-procurement-evaluation-award` |
| `procurement-compliance` | `procest-procurement-compliance` |
| `publication-platform-integration` | `procest-procurement-publication-platform` |

## Judgement calls

- **7 vs 8 split for publication-platform.** Kept as a standalone spec
  (#7). TED/OJEU is *not* identical to TenderNed/Mercell transport
  glue — it has its own statutory deadlines (rectification windows,
  standard form codes F01..F25 + eForms), its own "material change"
  flag triggering re-publication, and its own bidirectional flow
  (publish → confirmation → indexing). Folding it into PSI would
  obscure those constraints. PSI declares the *connector slot* (where
  the OpenConnector source plugs in); PPP declares the *publication
  workflow* (what gets published when, what re-publication means
  domain-wise).
- **PSA is light by design.** Procest does not own analytics; mydash
  does. PSA is a cross-app contract spec — it nails down the event
  shape procest emits (CloudEvents per `events + webhooks`) and the
  RBAC scope on the GraphQL query mydash will use. The actual widget
  declarations belong to mydash's own fleet rollout.
- **No `procest-procurement-purchase-order` spec.** Purchase orders
  (PO/Bestelling) are a procest case-type seed once CLM ships — they
  are a "contract child" lifecycle, not a separate capability. If
  market-intelligence later surfaces PO as its own surface, split
  off then.
- **Supplier-performance folded into SUP.** Performance scorecards
  are a `x-openregister-calculations` on Supplier + an aggregation;
  a separate spec would just restate the same Supplier schema with a
  scoreboard widget. One spec keeps the supplier surface coherent.

## Risks + mitigations

| Risk | Mitigation |
|---|---|
| Procest's `case-management` already declares a `decision` register; the EVA "award" spec must not duplicate it. | EVA reuses procest's existing `decision` schema; adds `awardType`, `evaluationRef`, `standstillUntil` fields via additive register patch, no new register. |
| OpenConnector sources for TenderNed/TED don't exist yet. | PSI + PPP describe the *slot*, not the transport. A separate `add-openconnector-eu-procurement-sources` change owns the connector definitions. PSI's manifest entry stays hidden until the sources register. |
| The 26 source drafts have weak NL-gov coverage; new specs add Aanbestedingswet 2012, ARW 2016, UEA, EML-bestand, Alcatel-termijn citations. | Citations are inline in each REQ's narrative; reviewer can verify against the cited articles. |
| financeq doesn't exist. | Every cross-app reference to financeq is prefixed `[future]`; manifests don't yet hard-depend on it. |

## See also

- ADR-022 — apps consume OR abstractions (the OR-side anti-pattern list).
- ADR-024 — app manifest (every spec ends with a manifest entry).
- ADR-031 — schema-declarative business logic (every lifecycle/aggregation/notification declared in the register, not coded as a service).
- ADR-032 — spec sizing + chained-spec routing (this change is `kind: config`; per-spec code chains will follow).
- Procest `case-management`, `case-types`, `workflow-engine-abstraction`, `roles-decisions`, `deelzaak-support`, `besluitvorming-workflow` — existing capabilities every new spec builds on.
- `feedback_mydash-no-or-dependency.md` — PSA contract shape.
- Intelligence-DB cleanup checklist in "Source draft reconciliation" above.



## Tasks

# Tasks: add-procest-procurement-suite

This is a `kind: config` change per ADR-032. Tasks here describe
**spec-authoring + reviewer verification** only. No PHP, no Vue, no
tests, no register-file patches. Implementation lives in follow-up
code chains (one per spec) opened after this change archives.

## Spec authoring (this change)

- [x] **T1** — Draft `proposal.md` with consolidation rationale and
  source-draft → consolidated-spec mapping.
  - files: `proposal.md`
  - spec_ref: this change's `proposal.md`

- [x] **T2** — Draft `design.md` with domain framing, OR abstraction
  usage matrix, declarative-vs-imperative classification, 7-vs-8 split
  rationale, and intelligence-DB cleanup checklist.
  - files: `design.md`
  - spec_ref: ADR-022, ADR-031, ADR-032

- [x] **T3** — Author `procest-procurement-supplier-management/spec.md`
  consolidating 9 source drafts (`supplier-management`,
  `supplier-management-ai`, `supplier-management-misc`,
  `supplier-management-other-t1..t5`, `supplier-performance-management`).
  - files: `specs/procest-procurement-supplier-management/spec.md`
  - acceptance: 8 REQ-SUP-* requirements, each with ≥1 scenario,
    Supplier register declared with Schema.org annotation,
    no-parallel-storage reviewer-gate scenario present.

- [x] **T4** — Author `procest-procurement-contract-lifecycle/spec.md`
  consolidating 8 source drafts (`contract-lifecycle-management`,
  `-ai`, `-analytics`, `-document-management`, `-other-t1..t4`).
  - files: `specs/procest-procurement-contract-lifecycle/spec.md`
  - acceptance: 8 REQ-CLM-* requirements, contract-as-case framing,
    docudesk signing via OpenConnector source.

- [x] **T5** — Author `procest-procurement-system-integration/spec.md`
  consolidating 5 source drafts (`procurement-integration`,
  `-integration`, `-other-t1..t3`).
  - files: `specs/procest-procurement-system-integration/spec.md`
  - acceptance: 6 REQ-PSI-* requirements, every external system
    declared as an OpenConnector source slot (not as a procest
    service), connector slot table present.

- [x] **T6** — Author `procest-procurement-tender-management/spec.md`
  from the `tender-management` draft.
  - files: `specs/procest-procurement-tender-management/spec.md`
  - acceptance: 9 REQ-TND-* requirements, tender-as-case framing,
    Aanbestedingswet 2012 + ARW 2016 citations, sub-case (lot)
    support via procest's `deelzaak-support`.

- [x] **T7** — Author `procest-procurement-evaluation-award/spec.md`
  from the `evaluation-award` draft.
  - files: `specs/procest-procurement-evaluation-award/spec.md`
  - acceptance: 7 REQ-EVA-* requirements, reuse of procest's existing
    `decision` register (no new award register), Alcatel-termijn
    documented, motiveringsplicht referenced.

- [x] **T8** — Author `procest-procurement-compliance/spec.md` from
  the `procurement-compliance` draft.
  - files: `specs/procest-procurement-compliance/spec.md`
  - acceptance: 7 REQ-PCC-* requirements, UEA + EML-bestand modelled
    as registers (not as PHP enums), declarative threshold checks per
    ADR-031.

- [x] **T9** — Author `procest-procurement-publication-platform/spec.md`
  from the `publication-platform-integration` draft.
  - files: `specs/procest-procurement-publication-platform/spec.md`
  - acceptance: 6 REQ-PPP-* requirements, TED eForms F01..F25 modelled
    as a publication-template register, "material change → re-publish"
    handled as a lifecycle transition, not as a PHP service.

- [x] **T10** — Author
  `procest-procurement-spend-analytics-integration/spec.md` as a
  cross-app contract spec.
  - files: `specs/procest-procurement-spend-analytics-integration/spec.md`
  - acceptance: 5 REQ-PSA-* requirements, CloudEvent schemas for every
    domain event emitted, mydash GraphQL query shape declared, ADR-024
    §10 (no OR dep on mydash) re-cited.

## Reviewer verification (this change — pre-merge)

- [ ] **T11** — Reviewer confirms every spec carries `Status`, `Scope`,
  `Tier`, `Depends on` header per the shillinq reference style.
  - files: all `specs/*/spec.md`
  - acceptance: 8/8 headers present, all 4 fields populated.

- [ ] **T12** — Reviewer confirms every register declared in any spec
  has a Schema.org annotation on the schema row.
  - files: all `specs/*/spec.md` field tables.
  - acceptance: 100% of register definitions annotated.

- [ ] **T13** — Reviewer confirms every lifecycle is declared as
  `x-openregister-lifecycle` in the REQ prose, never as a PHP service.
  ADR-031 anti-pattern scan.
  - acceptance: zero references to `Service::transition`,
    `Service::advance*`, `Service::setStatus*` in REQ prose.

- [ ] **T14** — Reviewer confirms every spec ends with a manifest-
  navigation requirement per ADR-024.
  - acceptance: 8/8 specs have a final `REQ-<prefix>-NNN` describing
    the manifest entries the suite contributes.

- [ ] **T15** — Reviewer confirms every spec includes at least one
  "no parallel storage" scenario (ADR-022 anti-pattern reviewer-gate).
  - acceptance: 8/8 specs scan-clean for `lib/Db/{*}_mapper.php`
    style scenarios.

- [ ] **T16** — Deduplication check (ADR-012, per hydra/CLAUDE.md
  design rules): verify no register declared in this suite duplicates
  an existing procest register (`case`, `caseType`, `decision`,
  `parafeerroute`, etc.).
  - acceptance: only additive register patches; reused registers
    explicitly cited as "extends procest's existing `<name>`".

## Post-merge follow-up (NOT this change)

The following land as separate efforts and are listed here only so
the consolidation hand-off is unambiguous. **Do not author them as
tasks in this change — per `feedback_opsx-no-process-tasks.md`,
PR/merge/archive process tasks do not belong in opsx tasks.md.**

- Per-spec code chains (one per spec, each a chain of `kind: config`
  register patch → `kind: code` manifest wiring → `kind: code` guard
  classes if any).
- Intelligence-DB cleanup script that flips the 26 source drafts to
  `status: superseded` (see `design.md` "Source draft reconciliation"
  table for the exact slug list).
- `add-openconnector-eu-procurement-sources` change in the
  openconnector repo that lands the actual source rows referenced by
  PSI + PPP.
- `[future]` financeq integration spec, once the financeq repo exists.
