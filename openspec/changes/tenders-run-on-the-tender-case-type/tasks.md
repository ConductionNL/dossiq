# Tasks: tenders-run-on-the-tender-case-type

## 1. Package

- [x] 1.1 Schemas `bid`, `tenderQuestion`, `evaluation`, `scoringFormula` per REQ-TND-004, REQ-TND-007, REQ-EVA-001 and REQ-EVA-003, shipped with the `aanbesteding` package (cross-app schema references, decision 198) or in `lib/Settings/register.d/` if the installer cannot ship schemas yet. Built in `lib/Settings/register.d/88-tender-records.json` (the installer ships no schemas yet): the four schemas with the fields the specs name, declared lifecycles for bid, question and evaluation (a late bid goes from received straight to inadmissible), the three common scoring formulas seeded as records, config keys in `SchemaSlugMap`, register 0.20.97. No award schema: the award is a decision. `tests/Unit/Settings/TenderRecordsSchemasTest.php`.
- [x] 1.2 `lib/Settings/packages/aanbesteding/flows/aanbesteding.json`: the flow over Voorbereiding to Gegund, guarded by the closing date and the standstill term. Built with a generic `dateReached` guard (`lib/Service/Transitions/DateReachedGuard.php`) on the case's `sluiting` (only after the day) and `opschortendeTermijnEinde`; the case type declares both and `gunningsdatum`. Filling `opschortendeTermijnEinde` from the 20-day term is task 1.3.
- [ ] 1.3 Decision types for award and refusal (REQ-EVA-002); terms for the question deadline and the 20-day standstill (REQ-TND-006, REQ-EVA-005) in the package.
      Partly built: the package's `seed/` part ships the decision types Gunningsbeslissing and Afwijzingsbeslissing on the tender case type and the 20-day standstill term (Aw 2012 art. 2.127, calendar days, bound to the case type slug); package 1.9.0; `tests/Unit/Settings/AanbestedingPackageSeedTest.php`. Left: the question-deadline term, which is a date each tender sets in its own timeline rather than a fixed duration, so it needs a decision on how it is modelled.
- [ ] 1.4 `examples/`: one tender in evaluation with two lots and three bids; bump the package version.
- [ ] 1.5 `tests/integration/packages/aanbesteding.postman_collection.json`: install, register a bid, refuse a late bid, score, award.

## 2. Scoring

- [x] 2.1 Check whether an OpenRegister stored decision table (decision 186) can hold the three formulas; if yes, seed them as tables; if not, `lib/Service/Scoring/FormulaScorer.php` with `tests/Unit/Service/Scoring/FormulaScorerTest.php`. Checked: OpenRegister's decision tables match unary tests on inputs and return literal outputs, so they cannot compute a price score; FormulaScorer evaluates a stored expression instead.

## 3. Case page

- [ ] 3.1 Wait for board DqAanbesteding, then place on CaseDetail (`src/manifest.json`) the widgets it draws (lots as sub-cases, questions and answers, bids, evaluation, award), `visibleWhen: "@context.caseType.tenderChain"`; the package sets `tenderChain: true`.
- [ ] 3.2 Header actions `tender-answer-publish`, `bid-register`, `bid-score`, `award-propose`, dialogs under `src/dialogs/` named by what they do.
- [ ] 3.3 The award hands off to shillinq and decidiq through `lib/Service/ContractDecisionDelegationService.php` (no new contract code).

## 4. Checks

- [ ] 4.1 `tests/e2e/tender-case.spec.ts`: the three scenarios of REQ-TND-CASE-01.
- [ ] 4.2 Live pass against DqAanbesteding and DqContracten.
- [ ] 4.3 `openspec/parity/capabilities.json` row 2.54: built when 1 to 3 are live.
