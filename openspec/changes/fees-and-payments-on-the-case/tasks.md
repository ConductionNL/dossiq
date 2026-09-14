# Tasks: fees-and-payments-on-the-case

Tier: V1. Kind: code. Size S. Round 4 discovery cluster 55 and gap
register rows 1.11 and 12.12, the dossiq consumer half of shillinq
`fees-payments-and-the-contract-register` (ConductionNL/shillinq#1608).
Candidates C-intake-44, C-intake-7, C-deadlines-10 and
C-parties-and-contacts-1. C-intake-38 is shillinq's whole. Decision D6
admits the `must` on one driven passer.

- [ ] 1.1 `caseType`: the fee as a list of entries, each with the intake
  channel, the amount and the article of the legesverordening (D-1).
  - `tests/unit/Service/CaseTypeFeeDeclarationTest.php`
  - `@spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md`
- [ ] 1.2 Raise the payment request in shillinq on creation, with the case,
  the channel's amount and the article; warn on publication when an entry
  has no article (D-1).
- [ ] 2.1 `case`: the payment state as a projection read from shillinq,
  with no amount, no ledger line and no payment date held as a source
  (D-2).
  - `tests/unit/Service/PaymentStateProjectionTest.php`
- [ ] 2.2 A projection that cannot be refreshed reads stale, never paid
  (D-2).
  - `tests/vitest/paymentStateOnCase.spec.js`
- [ ] 3.1 The manual override as a permissioned act recording who, when
  and why, refused to anyone without the financial role (D-3).
  - `tests/unit/Service/PaymentOverrideTest.php`
- [ ] 4.1 `caseType`: declare whether an unpaid case may proceed; a guard
  in `CaseActionProvider` refuses the dependent acts with the rule named
  per ADR-050 (D-4).
  - `tests/unit/Lifecycle/UnpaidCaseGuardTest.php`
- [ ] 4.2 Refuse rather than allow when the payment state cannot be read
  and payment is required, per ADR-102 (D-4).
- [ ] 5.1 `case`: reference a shillinq contract; answer the cases raised
  under a contract; copy no term, cost or renewal date (D-5).
  - `tests/unit/Service/CaseContractReferenceTest.php`
- [ ] 5.2 Dutch and English strings.
- [ ] 5.3 `tests/e2e/fees-and-payments-on-the-case.spec.ts`: two intake
  channels with two amounts, the article named on the case, an outstanding
  state on the case and in the list, a stale projection that does not read
  paid, a balie payment recorded by the financial role and refused to a
  handler, an unpaid case refused and a melding proceeding, and a contract
  listing its cases;
  `openspec validate fees-and-payments-on-the-case --strict`.
