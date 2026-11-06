# Tasks: tenders-run-on-the-tender-case-type

## 1. Package

- [ ] 1.1 Schemas `bid`, `tenderQuestion`, `evaluation`, `scoringFormula` per REQ-TND-004, REQ-TND-007, REQ-EVA-001 and REQ-EVA-003, shipped with the `aanbesteding` package (cross-app schema references, decision 198) or in `lib/Settings/register.d/` if the installer cannot ship schemas yet.
- [ ] 1.2 `lib/Settings/packages/aanbesteding/flows/aanbesteding.json`: the flow over Voorbereiding to Gegund, guarded by the closing date and the standstill term.
- [ ] 1.3 Decision types for award and refusal (REQ-EVA-002); terms for the question deadline and the 20-day standstill (REQ-TND-006, REQ-EVA-005) in the package.
- [ ] 1.4 `examples/`: one tender in evaluation with two lots and three bids; bump the package version.
- [ ] 1.5 `tests/integration/packages/aanbesteding.postman_collection.json`: install, register a bid, refuse a late bid, score, award.

## 2. Scoring

- [ ] 2.1 Check whether an OpenRegister stored decision table (decision 186) can hold the three formulas; if yes, seed them as tables; if not, `lib/Service/Scoring/FormulaScorer.php` with `tests/Unit/Service/Scoring/FormulaScorerTest.php`.

## 3. Case page

- [ ] 3.1 Wait for board DqAanbesteding, then place on CaseDetail (`src/manifest.json`) the widgets it draws (lots as sub-cases, questions and answers, bids, evaluation, award), `visibleWhen: "@context.caseType.tenderChain"`; the package sets `tenderChain: true`.
- [ ] 3.2 Header actions `tender-answer-publish`, `bid-register`, `bid-score`, `award-propose`, dialogs under `src/dialogs/` named by what they do.
- [ ] 3.3 The award hands off to shillinq and decidiq through `lib/Service/ContractDecisionDelegationService.php` (no new contract code).

## 4. Checks

- [ ] 4.1 `tests/e2e/tender-case.spec.ts`: the three scenarios of REQ-TND-CASE-01.
- [ ] 4.2 Live pass against DqAanbesteding and DqContracten.
- [ ] 4.3 `openspec/parity/capabilities.json` row 2.54: built when 1 to 3 are live.
