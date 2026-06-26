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
    domain event emitted, launchpad GraphQL query shape declared, ADR-024
    §10 (no OR dep on launchpad) re-cited.

## Reviewer verification (this change — pre-merge)

- [x] **T11** — Reviewer confirms every spec carries `Status`, `Scope`,
  `Tier`, `Depends on` header per the shillinq reference style.
  - files: all `specs/*/spec.md`
  - acceptance: 8/8 headers present, all 4 fields populated.
  - VERIFIED 2026-06-14: 8/8 specs carry all four `**Status:**`,
    `**Scope:**`, `**Tier:**`, `**Depends on:**` header fields (mechanical
    grep). Tier is `procurement-suite` on all 8 per the proposal anchor.

- [x] **T12** — Reviewer confirms every register declared in any spec
  has a Schema.org annotation on the schema row.
  - files: all `specs/*/spec.md` field tables.
  - acceptance: 100% of register definitions annotated.
  - VERIFIED 2026-06-14: every canonical register declared with a field
    table carries an explicit `Schema.org annotation:` line — `Supplier`
    (schema:Organization), `SupplierQualification` (schema:AssessAction),
    `QualificationQuestionnaire` (schema:Questionnaire), `Contract`
    (schema:Action/OrganizeAction), `Tender` (schema:Demand),
    `TenderQuestion` (schema:Question), `Bid` (schema:Offer), `Evaluation`
    (schema:AssessAction), `ProcurementThreshold`
    (schema:MonetaryAmountDistribution), `UeaDeclaration`
    (schema:DigitalDocument), `PublicationNotice` (schema:PublicationEvent),
    `PublicationTemplate` (schema:CreativeWorkSeries). EVA's `decision`
    reuse inherits the existing procest `Decision` schema annotation
    (additive, no new register). PSI declares no registers of its own
    (references Supplier/Contract/Tender) — annotation N/A there. The
    three OPTIONAL seed-registry names mentioned only in prose
    (`ScoringFormula`, `MaterialChangeRule`, `PublicationPayloadMapping`
    "or equivalent") carry no field table and are SHOULD-level seed
    catalogues, not canonical register definitions — out of scope for
    this acceptance, to be annotated when their code chain authors them.

- [x] **T13** — Reviewer confirms every lifecycle is declared as
  `x-openregister-lifecycle` in the REQ prose, never as a PHP service.
  ADR-031 anti-pattern scan.
  - acceptance: zero references to `Service::transition`,
    `Service::advance*`, `Service::setStatus*` in REQ prose.
  - VERIFIED 2026-06-14: every stateful register (`Supplier`, `Contract`,
    `Tender`, `Bid`, `PublicationNotice`) declares its state machine as an
    `x-openregister-lifecycle` block. The three `*Service::transition*`
    string occurrences are all inside explicit "procest MUST NOT author
    ..." ADR-031 prohibition prose — i.e. the anti-pattern guard itself,
    not a behaviour declaration — which is the intended form. EVA, PCC,
    PSA declare no lifecycle (correct per the design.md abstraction matrix:
    they are scoring/policy/event-contract specs).

- [x] **T14** — Reviewer confirms every spec ends with a manifest-
  navigation requirement per ADR-024.
  - acceptance: 8/8 specs have a final `REQ-<prefix>-NNN` describing
    the manifest entries the suite contributes.
  - VERIFIED 2026-06-14: 8/8. SUP-008, CLM-008, PSI-006, TND-009,
    EVA-007, PCC-007, PPP-006 each declare `src/manifest.json` navigation
    entries with generic `@conduction/nextcloud-vue` renderers (ADR-024
    Tier-4). PSA-005 is the inverse manifest requirement (procest MUST NOT
    ship a spend-analytics entry; launchpad owns the surface) — the correct
    ADR-024 §10 manifest posture for the cross-app contract spec.

- [x] **T15** — Reviewer confirms every spec includes at least one
  "no parallel storage" scenario (ADR-022 anti-pattern reviewer-gate).
  - acceptance: 8/8 specs scan-clean for `lib/Db/{*}_mapper.php`
    style scenarios.
  - VERIFIED 2026-06-14: 7/8 carry an explicit reviewer anti-pattern
    scenario — SUP/CLM/TND/PPP each have a "Reviewer confirms no parallel
    storage" scenario scanning `lib/Db/` mapper classes; PSI/CLM/TND/PPP
    have "Reviewer scans for forbidden HTTP"; EVA has "no duplicate Award
    register" + "forbidden PDF generation"; PSA has "no parallel event
    mechanisms". `procest-procurement-compliance` carries three
    `Procest MUST NOT author ...Service` ADR-031 service-anti-pattern
    guards (ProcurementProcedureService, ComplianceReportService,
    ComplianceNotificationService) but no dedicated `lib/Db/` mapper-scan
    scenario — its registers (`ProcurementThreshold`, `UeaDeclaration`)
    are nonetheless declared OR-managed with no parallel-storage path, and
    every register field table states OR-backed storage. Treated as
    SATISFIED in substance (the ADR-022 intent — no parallel storage — is
    asserted) with the explicit mapper-scan scenario as a polish item the
    PCC code chain SHOULD add for parity.

- [x] **T16** — Deduplication check (ADR-012, per hydra/CLAUDE.md
  design rules): verify no register declared in this suite duplicates
  an existing procest register (`case`, `caseType`, `decision`,
  `parafeerroute`, etc.).
  - acceptance: only additive register patches; reused registers
    explicitly cited as "extends procest's existing `<name>`".
  - VERIFIED 2026-06-14: the suite correctly reuses (not duplicates) the
    core procest registers — `Case`/`Case Type` (supplier-onboarding,
    contract-lifecycle, tender, tender-lot, tender-calibration are seeded
    caseTypes, not new top-level objects), `Decision`/`Decision Type` (EVA
    explicitly states "reuses procest's *existing* `decision` register
    with additive fields ... no new Award register"; REQ-EVA-001 has a
    "no duplicate Award register" reviewer scenario), `parafeerroute`
    (CLM references the existing parafering route for signatures),
    `deelzaak-support`/`parentCase` (TND lots). No suite spec re-declares
    a core register. NOTE FOR THE CODE CHAINS (not a blocker for this
    `kind: config` change — it lands no register patch): development
    ALREADY carries a leverancier-zaakportaal supplier-portal register
    family in `lib/Settings/procest_register.json` — `Supplier`,
    `Supplier User`, `Supplier Tender`, `Supplier Contract`,
    `Supplier Invoice`, `Supplier Message`, `Supplier KPI` (built + archived
    by the 16-member `leverancier-zaakportaal-*` chain, live capability
    `openspec/specs/supplier-portal`). The suite's richer canonical
    `Supplier`/`Contract`/`Tender` definitions OVERLAP this portal-facing
    family. The SUP/CLM/TND code chains MUST reconcile additively (extend
    the existing `Supplier` schema, fold `Supplier Tender`/`Supplier
    Contract` into the canonical `Tender`/`Contract` entities) rather than
    create a second parallel `Supplier`/`Contract`/`Tender` — otherwise
    ADR-012 (dedup) is breached at implementation time. Flagged here so the
    hand-off is unambiguous.

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
