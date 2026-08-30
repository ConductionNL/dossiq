# Tasks: bezwaar-advisory-committee

This is a **GENERATE-style** (spec-only) change. No PHP, Vue, or schema changes are introduced — implementation is deferred to a downstream `opsx-apply` change against a frozen contract. Tasks below validate the spec's internal coherence and its alignment with Awb Art. 7:13.

## Schema sketch

- [ ] **T01**: Sketch the `bezwaaradviescommissie` and `bac_advice_request` schemas in `design.md` covering the property tables (committee: `name`, `domain`, `chair`, `members`, `secretary`, `quorum`, `term_starts_on`, `term_ends_on`, `status`; advice request: `bezwaar_case`, `committee`, `panel`, `status`, `assigned_at`, `deadline`, `advice_document`, `hearing_report_ref`). Confirm property names align with Procest naming conventions (snake_case → camelCase mapping noted) and OpenRegister schema patterns (`$ref`, `onDelete: CASCADE` for the `bezwaar_case` link). No code, no JSON file — just the documented sketch in design.md.

## Lifecycle modeling

- [ ] **T02**: Verify the three-state advice-request lifecycle (`assigned → in-deliberation → advice-issued`) is fully covered in `design.md` with explicit transition triggers, including: panel-finalization gate (independence check), and chair-signature gate (content-contract + quorum check). Confirm no orphan states or unreachable transitions. Record the rationale for omitting `withdrawn` and `rejected` states.

## Advice document template

- [ ] **T03**: Document the advice document content contract (Awb Art. 7:13(7)) in `design.md`: required fields `findings`, `hearing_summary_ref`, `legal_assessment`, `conclusion` (enum: `gegrond | ongegrond | gedeeltelijk_gegrond | niet_ontvankelijk`), `recommendation`, optional `dissenting_opinions`, `signed_by_chair_at`, `signature_evidence`. Cross-reference REQ-BAC-4. Confirm minimum string lengths (≥ 50 chars on `findings` and `legal_assessment`) are realistic against representative real-world advices. File a follow-up issue if a stricter length is recommended.

## Council deviation rule

- [ ] **T04**: Document REQ-BAC-5 (council deviation justification) in both `design.md` and `specs/bezwaar-advisory-committee/spec.md`. Confirm the rule reads: "when the besluit op bezwaar's outcome differs from `advice.conclusion`, the besluit SHALL carry a non-empty `motivatie_afwijking_advies` field". Cross-link to the sister `bezwaar-lifecycle` change so the guard implementation can be wired there. File a follow-up issue if `bezwaar-lifecycle` has not yet added the guard, so it is tracked rather than left implicit.

## Independence rule

- [ ] **T05**: Document REQ-BAC-2 (member independence under Awb Art. 7:13(3)) — at the moment of panel assignment, no panel member's `nc_uid` may equal the `besluit.steller` or any signatory recorded on the original (contested) primair besluit. Confirm that the rule fires at `assigned → in-deliberation` (not at committee-creation). Record a worked example: a committee containing user `J. de Vries` is valid for bezwaar #A123 but invalid for bezwaar #A124 because `J. de Vries` signed the latter's primair besluit.

## Audit trail

- [ ] **T06**: Document the `bac_audit_trail` field on the advice request: explicit events appended (panel-member-added/removed, independence-check-failed, advice-signed-by-chair, council-deviation-recorded) plus the implicit per-save OpenRegister audit log. Confirm Archiefwet (Dutch Public Records Act) accountability is satisfied by the combination. No implementation — design contract only.

## Cross-spec coordination

- [ ] **T07**: Add a "Cross-Project Dependencies" cross-reference in this change's `proposal.md` pointing to `bezwaar-lifecycle` (sister) and `bezwaar-beroep-workflow` (parent context). Once the sibling `bezwaar-lifecycle` change is merged, file a follow-up issue to add a "See also: `bezwaar-advisory-committee`" line in its proposal.md as well. Skip if already present.

## Pre-commit verification

- [ ] **V01**: `openspec validate bezwaar-advisory-committee --type change --strict` → exit code 0
- [ ] **V02**: `openspec change show bezwaar-advisory-committee --json --deltas-only | jq '.deltaCount'` → ≥ 8 (one delta per REQ-BAC-1..8)
- [ ] **V03**: Every REQ-BAC-* in `specs/bezwaar-advisory-committee/spec.md` carries at least one `#### Scenario:` block (per `openspec` strict mode requirement)
- [ ] **V04**: No files modified outside `openspec/changes/bezwaar-advisory-committee/` (verify with `git diff --name-only origin/development...HEAD`)
- [ ] **V05**: No `Co-Authored-By` trailer in the commit message (verify with `git log -1 --pretty=%B | grep -i 'co-authored-by'` → empty)
