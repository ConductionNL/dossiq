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

- [~] **T11** — Reviewer confirms every spec carries `Status`, `Scope`, — deferred to downstream cycle / fleet-wide adoption (handoff)
  `Tier`, `Depends on` header per the shillinq reference style.
  - files: all `specs/*/spec.md`
  - acceptance: 8/8 headers present, all 4 fields populated.

- [~] **T12** — Reviewer confirms every register declared in any spec — deferred to downstream cycle / fleet-wide adoption (handoff)
  has a Schema.org annotation on the schema row.
  - files: all `specs/*/spec.md` field tables.
  - acceptance: 100% of register definitions annotated.

- [~] **T13** — Reviewer confirms every lifecycle is declared as — deferred to downstream cycle / fleet-wide adoption (handoff)
  `x-openregister-lifecycle` in the REQ prose, never as a PHP service.
  ADR-031 anti-pattern scan.
  - acceptance: zero references to `Service::transition`,
    `Service::advance*`, `Service::setStatus*` in REQ prose.

- [~] **T14** — Reviewer confirms every spec ends with a manifest- — deferred to downstream cycle / fleet-wide adoption (handoff)
  navigation requirement per ADR-024.
  - acceptance: 8/8 specs have a final `REQ-<prefix>-NNN` describing
    the manifest entries the suite contributes.

- [~] **T15** — Reviewer confirms every spec includes at least one — deferred to downstream cycle / fleet-wide adoption (handoff)
  "no parallel storage" scenario (ADR-022 anti-pattern reviewer-gate).
  - acceptance: 8/8 specs scan-clean for `lib/Db/{*}_mapper.php`
    style scenarios.

- [~] **T16** — Deduplication check (ADR-012, per hydra/CLAUDE.md — deferred to downstream cycle / fleet-wide adoption (handoff)
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
