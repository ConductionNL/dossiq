# Tasks: receipt-names-the-case-number

- [x] 1. `TermLetters::render()` prefers the caller's kenmerk over the term's case link, without new branches (the class sits at its PHPMD complexity limit).
- [x] 2. `AcknowledgementDutyTest::testTheReceiptNamesTheCaseNumberNotTheTermsCaseLink` drives the real caller with a UUID case link; it fails on the old order.
