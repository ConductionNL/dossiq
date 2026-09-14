# Tasks: decision-outcomes-on-the-case

Tier: V1. Kind: code. Size S. Round 4 discovery cluster 22, the dossiq
consumer half of decidiq `the-decision-as-a-walked-process`
(ConductionNL/decidiq#1316). Candidates C-decisions-1, C-decisions-13 and
C-decisions-25. Decision D6 admits all three on relevance. The walk, the
approvers and the thresholds are decidiq's.

- [ ] 1.1 `caseType`: declare which acts require a walked approval (D-1).
  - `tests/unit/Service/ApprovalGateDeclarationTest.php`
  - `@spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md`
- [ ] 1.2 A guard in `CaseActionProvider` reading decidiq's outcome,
  refusing the gated act with the approval named per ADR-050 (D-1).
  - `tests/unit/Lifecycle/ApprovalGuardTest.php`
- [ ] 1.3 An outcome dossiq cannot read blocks and says the approval
  service is unavailable, per ADR-102 (D-2).
- [ ] 1.4 The case shows what it waits for and on whom (D-1).
  - `tests/vitest/caseAwaitingApproval.spec.js`
- [ ] 2.1 `caseType`: declare an admissibility judgement at intake (D-3).
  - `tests/unit/Service/AdmissibilityJudgementTest.php`
- [ ] 2.2 An inadmissible verdict closes the case through the ordinary
  close act with a niet-ontvankelijk result, records the judge, and tells
  the applicant through the declared moment (D-3).
- [ ] 3.1 `caseType`: declare the remedy kind, term and body (D-4).
  - `tests/unit/Service/RemedyClauseDeclarationTest.php`
- [ ] 3.2 Print the clause on the decision document from the declaration,
  not from a template; warn on publication when it is missing (D-4).
- [ ] 3.3 Bind a term instance of kind `remedy` when the decision is sent,
  so "still open to bezwaar" is answerable (D-4).
  - `tests/unit/Service/RemedyTermBindingTest.php`
- [ ] 3.4 Dutch and English strings.
- [ ] 3.5 `tests/e2e/decision-outcomes-on-the-case.spec.ts`: a besluit
  refused for an outstanding approval, a case naming its approvers, an
  approved case proceeding, an inadmissible verdict closing at intake with
  the applicant told, a bezwaarclausule printed from the declaration, and
  a remedy term read as expired;
  `openspec validate decision-outcomes-on-the-case --strict`.
