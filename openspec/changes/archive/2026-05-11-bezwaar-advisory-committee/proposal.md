# Proposal: Bezwaar Advisory Committee (Bezwaaradviescommissie)

## Summary

Add a dedicated `bezwaar-advisory-committee` capability to Procest that models the optional independent advisory committee (bezwaaradviescommissie / bac) that, under **Awb Art. 7:13**, reviews a citizen's objection and issues a written advice to the council (bestuursorgaan) before the council takes its decision-on-objection. This sister capability extends the parent `bezwaar-lifecycle` change by formalizing the committee as a first-class entity, capturing its composition (chair + ≥ 2 members, none of whom are civil servants involved in the contested decision), the advice request lifecycle (`assigned → in-deliberation → advice-issued`), the advice document with all Art. 7:13(7) required content, the council's deviation-justification rule, and the per-action audit trail.

## Why

Tenders from HLT, Beverwijk, Winterswijk, Nissewaard, Den Helder and Horst aan de Maas all reference an "adviescommissie bezwaarschriften" track in their bezwaar requirements (the cluster carries 801 requirements across 209 tenders in our intelligence DB). The parent `bezwaar-lifecycle` spec stops at the workflow level — it lists "Advies commissie" as a process step but does not specify how the committee itself is composed, how cases are assigned to committees, what fields the advice document must contain, or how the council records a deviating decision. Without that detail, a municipality cannot prove **Awb-compliant** bezwaar handling and our spec coverage is materially incomplete for the cluster.

Modeling the advisory committee separately (rather than inlining everything into `bezwaar-lifecycle`) keeps the lifecycle spec focused on the statutory deadline + decision flow while letting compliance officers, scheme operators, and audit reviewers find the committee contract in one well-named capability. It also makes the committee re-usable from `complaint-management` and `consultation-management`, which use a similar advisory pattern.

## What Changes

- Adds the `bezwaar-advisory-committee` capability spec with eight requirements (REQ-BAC-1..8) in delta format under this change's `specs/` directory
- Adds a `design.md` describing the committee entity, advice-request lifecycle, advice document content contract, council deviation justification, and audit trail
- Adds eight verification + design-only tasks (T01–T08) — schema sketch, lifecycle FSM, advice template, council-deviation rule, audit trail, cross-spec links, validation gate
- Does **NOT** introduce any code changes — this is a SPEC-ONLY change; implementation is a downstream `opsx-apply` change

## Affected Projects

- [ ] Project: `procest` — Add the `bezwaar-advisory-committee` OpenSpec change with proposal, design, tasks, and delta spec. NO CODE.

## Scope

### In Scope (V1)

- **Committee entity** (REQ-BAC-1): composition rules, chair, members, secretary, term, jurisdiction
- **Member independence** (REQ-BAC-2): blocking civil servants involved in the contested decision per Awb Art. 7:13(3)
- **Advice request lifecycle** (REQ-BAC-3): `assigned → in-deliberation → advice-issued`
- **Advice document content** (REQ-BAC-4): findings, hearing report reference, conclusion, recommendation per Awb Art. 7:13(7)
- **Council deviation justification** (REQ-BAC-5): mandatory motivation when the council departs from the advice
- **Advice publication** (REQ-BAC-6): the advice is published with the besluit op bezwaar
- **Audit trail** (REQ-BAC-7): every committee action recorded immutably for Archiefwet compliance
- **Authorization model** (REQ-BAC-8): only committee members + bezwaar handler may read; only chair may issue advice

### Out of Scope

- The wider bezwaar lifecycle (handled by sibling `bezwaar-lifecycle` change)
- Hearing scheduling logistics (handled by `bezwaar-lifecycle` via Nextcloud Calendar)
- E-signature for the chair's signature on the advice (deferred to a follow-up; flagged in tasks)
- Workflow templates for non-bezwaar advisory committees (re-use is acknowledged but the abstract version is a later change)

## Approach

GENERATE-style spec change. The committee capability does not yet exist in code; this change writes the contract first so that a follow-up `opsx-apply` change can implement it against a stable spec. No source files are touched by this change.

1. **Proposal + design + tasks** describe what to build and how to verify it
2. **Delta spec** under `specs/bezwaar-advisory-committee/spec.md` with eight `## ADDED Requirements` entries (REQ-BAC-1..8) each carrying ≥ 1 Given/When/Then scenario
3. **Pre-commit gate** is `openspec validate bezwaar-advisory-committee --type change --strict`

## Cross-Project Dependencies

- **bezwaar-lifecycle** (sister spec, in progress): the parent objection lifecycle that hands off to this committee
- **bezwaar-beroep-workflow** (already drafted): the broader bezwaar/beroep workflow context that this capability lives inside
- **OpenRegister**: object storage and audit trail for committee + advice records
- **document-zaakdossier**: the advice document and hearing report are stored as case dossier files
