---
kind: code
depends_on: []
---

# Proposal: fees-and-payments-on-the-case

The dossiq consumer half of **shillinq
`fees-payments-and-the-contract-register`** (ConductionNL/shillinq#1608),
round 4 discovery cluster 55 "Money and obligations: leges, payments and
the contract register" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Five candidates, one
`must`, owner shillinq, size M. Gap register row 1.11, `leges-at-intake`,
lives in shillinq and dossiq consumes it.

The register records why the cluster carried nothing
(`procest/_gaps/gap-register.json`, `discovery.counts.uncarried_reason`,
cluster 55): "shillinq opened no parity umbrella; its two changes carry
gap register rows 1.11 and 12.12, not this cluster". shillinq#1608 has
opened it, and this is dossiq's side.

## Why

A vergunningaanvraag costs money. The legesverordening says how much, and
it says a different amount depending on whether the citizen applied at the
balie or through the portal. Today dossiq's case type says nothing about
either, so the fee is known by the person taking the aanvraag and by
nobody else.

Downstream, a case whose leges were never paid should not proceed, and a
handler cannot see whether they were. And a case raised under a contract,
an onderhoudscontract, a raamovereenkomst, does not name it.

## The candidates dossiq consumes

| candidate | relevance | dossiq | what dossiq's half is |
|---|---|---|---|
| C-intake-44 | must | no | the case type declares its fee, from the legesverordening and per intake channel |
| C-intake-7 | should | no | a handler sets the payment state by hand when the money arrived another way |
| C-deadlines-10 | should | partial | a case names the contract it is raised under, and the alert before that contract lapses is shillinq's |
| C-parties-and-contacts-1 | should | no | a case links to the contract record shillinq holds, and the contract can list its cases |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-intake-44: "xxllnc-zaken: Case type > Webformulier, Tarieven
  (case-type-editor-anatomy.md)".
- C-intake-7: "xxllnc-zaken: Payments (payments/spec.md)".
- C-deadlines-10: "decos-join: documented, /oplossingen/modules
  (Contractbeheer)".
- C-parties-and-contacts-1: "glpi: Contracts (Management, Contracts,
  front/contract_item.php, contractcost.php, ticket_contract.php)".

C-intake-38, single payments and large SEPA batches through a payment
provider, is shillinq's whole and dossiq builds no half of it.

**D6 was answered relevance-led**, so the `must` enters on one driven
passer and the three `should` candidates enter as the case-side halves of
it. **D17** does not reach this cluster.

## What changes

- A case type declares its fee: the amount, the article of the
  legesverordening it comes from, and a different amount per intake
  channel where the verordening sets one.
- Creating a case of a fee-bearing case type raises a payment request in
  shillinq, carrying the case, the amount and the article.
- The payment state sits on the case, read from shillinq, and is visible
  wherever the case is. dossiq holds no ledger and no amount received.
- A handler with the right role sets the payment state by hand when the
  money arrived another way, recording who said so, when, and why.
- A case type declares whether an unpaid case may proceed. Where it may
  not, the acts that depend on payment are refused with the rule named.
- A case names the contract it is raised under, from shillinq's contract
  register, and the contract can list the cases raised under it.

## Ownership

shillinq owns the fee schedule, the payment request, the provider, the
ledger, the contract register and the alert before a contract lapses.
dossiq owns the case type declaration, the payment state shown on the
case, the manual override, the gate, and the link to the contract.

| half | app | artefact |
|---|---|---|
| the fee schedule, the payment request and the provider | shillinq | `fees-payments-and-the-contract-register`, shillinq#1608, and `leges-at-intake`, the register's row 1.11 |
| the payment request raised from a case | shillinq | `case-payment-requests`, the register's row 12.12 |
| the contract record, its term, its costs and its lapse alert | shillinq | `fees-payments-and-the-contract-register`, shillinq#1608 |
| the payment request on the portal intake form | portaliq | the contribution contract, shipped |

Every half has an artefact. This change opens no request for a new change
in another repo.

## ADRs

- Company ADR-011: search OpenRegister before implementing a utility.
  dossiq stores no amount received, no ledger line and no contract.
- Company ADR-050: the error envelope is `{message, error}`. An act
  refused for an unpaid case names the rule in `error`.
- Company ADR-102: config absence fails closed with a status. Where a case
  type says an unpaid case may not proceed and shillinq cannot be read,
  the act is refused rather than allowed.

## Capabilities

- Modified: `financial-integration`: a case type declares its fee per
  intake channel, the case carries a payment state and names its contract.

## Impact

`caseType` (the fee declaration and the unpaid-case rule), `case` (the
payment state projection and the contract reference),
`lib/Service/FinancialIntegrationService.php`,
`lib/Lifecycle/CaseActionProvider.php`, the case page, Dutch and English
strings.

## Out of scope

- The payment provider, the SEPA batch and the ledger. shillinq.
- The contract register itself and its lapse alert. shillinq#1608.
- Subsidy settlement and case costs. dossiq
  `subsidie-settlement-case-costs`, shipped.
