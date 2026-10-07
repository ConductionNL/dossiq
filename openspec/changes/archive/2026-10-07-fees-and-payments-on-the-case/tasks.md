# Tasks: fees-and-payments-on-the-case

Tier: V1. Kind: code. Size S. Round 4 discovery cluster 55 and gap
register rows 1.11 and 12.12, the dossiq consumer half of shillinq
`fees-payments-and-the-contract-register` (ConductionNL/shillinq#1608,
built in shillinq#1637). Candidates C-intake-44, C-intake-7,
C-deadlines-10 and C-parties-and-contacts-1. C-intake-38 is shillinq's
whole. Decision D6 admits the `must` on one driven passer.

## What shillinq had already built, and what that changed here

shillinq#1637 shipped the fee schedule with per-channel `amounts` and a
structured `legalBasis` it refuses to publish without, the settlement as
an append behind `payment.administer`, the contract's `linkedObjects`, and
two leaves: `shillinq-payment-requests` and `shillinq-contracts`. Three of
the tasks below were written before that landed and asked dossiq to build
a second copy of it. They are answered by consuming it instead, and each
one says so where it stands.

- [x] 1.1 The fee is shillinq's schedule, not a caseType field. dossiq
  declares no amount, no currency and no citation: a second list of
  amounts is the two-sources-of-truth D-2 forbids for money. The case
  already carries `intakeChannel`, which is the one thing the resolution
  needs from here.
- [x] 1.2 Raising the leges is shillinq's
  `POST /api/payment-requests/leges`, which resolves the amount from the
  schedule for the case's channel and refuses a type with no published
  fee. dossiq's half is the placement of the panel that offers it
  (`case-payment-requests`), so a desk clerk raises it from the case.
  - `tests/vitest/paymentStateOnCase.spec.js`
- [x] 2.1 `case.paymentState`, `case.paymentStateCheckedAt`: the
  projection, read from shillinq's own report and carrying no amount, no
  ledger line and no payment date (D-2).
  - `tests/Unit/Service/Money/CasePaymentStateTest.php`
  - `tests/Unit/Service/Money/CasePaymentReaderTest.php`
- [x] 2.2 A projection that cannot be refreshed reads `stale`, never
  paid, in all four ways the read can fail: shillinq absent, the leaf
  unresolvable, the call throwing, the envelope unreadable. The hourly job
  writes NOTHING on a failed read rather than stamping `stale` over the
  last state anybody knew.
  - `lib/BackgroundJob/PaymentStateProjectionJob.php`
- [x] 3.1 The manual settlement is shillinq's act behind
  `payment.administer`, performed on the case through the panel. dossiq
  adds no second path and makes the projection read-only everywhere, so
  there is no editable field to guess at.
- [x] 4.1 `caseType.paymentRequiredBeforeHandling`, and the guard in
  `CaseActionProvider`: the dependent acts come back blocked with the rule
  named, and posting one anyway meets the same refusal (ADR-050).
  - `tests/Unit/Lifecycle/UnpaidCaseGuardTest.php`
  - `tests/Unit/Service/Money/UnpaidCaseGateTest.php`
- [x] 4.2 An unreadable state refuses rather than allows (ADR-102), and
  says the payment service could not be reached rather than accusing the
  citizen of not paying.
- [x] 5.1 `case.contract`: the reference, with no term, no cost and no
  renewal date copied. The other direction is shillinq's `linkedObjects`,
  and the panel that reads the contract is the `shillinq-contracts` leaf.
- [x] 5.2 Dutch and English strings, rebuilt into the browser catalogues.
- [x] 5.3 `tests/e2e/fees-and-payments-on-the-case.spec.ts`: two channels
  with two amounts, the article named on the case, an outstanding state on
  the case and in the list, a stale projection that does not read paid, an
  unpaid case refused on the WRITE path and a melding proceeding, and a
  contract named without its terms;
  `openspec validate fees-and-payments-on-the-case --strict`.

## Open, and named rather than quietly skipped

- The gate reads shillinq LIVE at the moment it refuses or allows, and the
  hourly projection exists for the list alone. That is deliberate: a
  refusal made on an hour-old word is a citizen who paid at the counter
  this morning being told they have not. It also means a case type that
  requires payment costs one cross-app read per listing of its acts; case
  types without the rule cost nothing, because the rule is asked first.
- The counter payment and the fee schedule are not reachable from a dossiq
  screen other than through shillinq's panels. An admin who wants to
  publish a fee does it in shillinq.
