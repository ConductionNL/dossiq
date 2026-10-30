# Tenders run on the tender case type

## Why

Row 2.54 (run tenders from publication through evaluation, award and contract management). The specs are written and already say how: a tender is a dossiq case (REQ-TND-001), bids are a register on the tender case (REQ-TND-007), scoring is an evaluation register with formulas as data (REQ-EVA-001, REQ-EVA-003), the award is a dossiq decision (REQ-EVA-002), lots are sub-cases (REQ-TND-005), standstill is a declarative term (REQ-EVA-005). The `aanbesteding` package ships the case type and its seven statuses. What is missing on development `a5c9b4153`: no bid, question, evaluation or scoring-formula schema, no flow in the package, and nothing on the case page to work a tender. The supplier side has `supplierTender` and `supplierContract` for the portal only. No change carried the build.

## What changes

- The `aanbesteding` package grows the parts the specs name: schemas `bid`, `tenderQuestion`, `evaluation` and `scoringFormula`, the flow over its statuses, the award decision types, and the standstill and question terms.
- The tender case page places widgets for lots, questions and answers (nota van inlichtingen), bids, evaluation and award, all configured on the case type (decision 182).
- Scoring is generic: an evaluation applies a stored formula (weighted criteria, lowest price, price-quality ratio) to bid scores. No tender class in `lib/`.
- Contract management stays with shillinq (the case Contract tab) and decidiq (contract decisions); this change hands the award to them through the existing `ContractDecisionDelegationService`.

## Impact

- `lib/Settings/packages/aanbesteding/` (case type, flows, schemas, decision types, terms, examples); the package installer's cross-app schema references (decision 198) if schemas need to live in the package.
- `src/manifest.json` CaseDetail widgets visible on `@context.caseType.tenderChain`.
- A generic formula evaluator (`lib/Service/Scoring/FormulaScorer.php`) if OpenRegister's stored decision tables (decision 186) cannot express the formulas; check that first.
- Board DqAanbesteding is not drawn yet (gap list b); DqContracten exists.
