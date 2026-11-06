---
kind: code
---

# Proposal: subsidie-settlement-appends-no-case-costs

## Why

`subsidie-settlement-case-costs` says that settling a grant appends a `subsidy_disbursement` entry to the linked case's `kosten`, and that dossiq's IV3 report counts it. The code stopped doing both under ADR-081: a domain app does not hold a ledger-shaped array, and Shillinq is the fleet's only general ledger. `VaststellingService::finalize()` now records the settled amount on the vaststelling and leaves the case alone, so the spec asserts a write the code does not make.

Decision 258 (Ruben, 30 October): ADR-081 wins. Settlement appends no case costs. The spec moves to the code.

## What changes

- The three requirements of `subsidie-settlement-case-costs` are removed: the `kosten` append, its idempotency and fail-soft rules, and the IV3 report counting `subsidy_disbursement`.
- One requirement takes their place: settling a grant writes the settled amount to the vaststelling and writes nothing to the linked case.
- A unit test proves a settlement with a linked case leaves that case unchanged.
- `grant-cases-from-application-to-settlement` drops its open question about case costs and cites decision 258.

## Not in this change

A disbursed grant is still municipal expenditure. It reaches the books as a Shillinq cost allocation, which is its own change once the dispatch seam exists.

## Rollback

Revert the commit.
