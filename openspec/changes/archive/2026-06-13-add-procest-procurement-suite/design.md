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
   procest emits domain events; consumers (launchpad via GraphQL,
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
                │ events → launchpad         │
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
- **PSA is light by design.** Procest does not own analytics; launchpad
  does. PSA is a cross-app contract spec — it nails down the event
  shape procest emits (CloudEvents per `events + webhooks`) and the
  RBAC scope on the GraphQL query launchpad will use. The actual widget
  declarations belong to launchpad's own fleet rollout.
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
- `feedback_launchpad-no-or-dependency.md` — PSA contract shape.
- Intelligence-DB cleanup checklist in "Source draft reconciliation" above.
