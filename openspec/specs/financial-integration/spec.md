---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# financial-integration Specification

## Purpose
Prepares an ERP-ready dwangsom payment signal once an amount is locked and processes the financial system's payment-confirmation callback via openconnector. The payment signal carries the full metadata (bedrag, rekeninghouder, IBAN, referentie, legal basis, payment deadline) and is blocked when the IBAN is missing or malformed; the signed callback is validated, looked up by referentie, updates the payment status to betaald, and triggers a burger notification, rejecting unknown references with HTTP 404.

## Requirements

### Requirement: Uitbetaling-signaal aan financieel systeem (REQ-TERM-007)

The system SHALL prepare an ERP-ready payment signal with all required metadata via openconnector
and SHALL process the ERP payment-confirmation callback. The callback endpoint MUST be
configured with a shared secret and MUST reject every request with HTTP 401 when that secret is
not configured — an unconfigured secret MUST NEVER be treated as an implicit pass. The secret
MUST be configurable via the dossiq admin settings UI.

**Feature tier**: MVP

#### Scenario: Payment signal generation

- **GIVEN** a `DwangsomBerekening` closes with a locked `definitievBedrag` and the burger's IBAN is known
- **WHEN** the payment signal is generated
- **THEN** a `DwangsomUitbetaling` SHALL be created with `bedrag`, `rekeninghouderNaam`, `iban`, `referentie` (zaakId + ingebrekestelling-date), `wettelijkeGrondslag` = "AWB 4:17 lid 2", `betaaldatumUiterlijk` = ingebrekestelling-date + 28 days, and `status` = `voorbereid`
- **AND** a `dwangsom-payment-signal` event SHALL be emitted to openconnector with the full metadata payload

#### Scenario: Missing or invalid IBAN blocks the signal

- **GIVEN** the burger's IBAN is missing or malformed
- **WHEN** `prepareBetaling` runs
- **THEN** the system SHALL raise an error and SHALL NOT emit a payment signal

#### Scenario: Payment confirmation callback updates status and notifies burger

- **GIVEN** the ERP sends a payment-confirmation callback via openconnector
- **WHEN** the signed callback arrives with `{referentie, status: betaald, werkelijkeBetaaldatum, betalingsreferentie}` and the configured `dwangsom_callback_secret` matches the request's HMAC-SHA256 signature
- **THEN** the `DwangsomUitbetaling` SHALL be looked up by `referentie` and its status updated to `betaald`
- **AND** a burger notification SHALL be triggered

#### Scenario: Unknown referentie is rejected

- **GIVEN** a callback arrives with a `referentie` that matches no `DwangsomUitbetaling`
- **WHEN** the callback is processed
- **THEN** the system SHALL respond with HTTP 404

#### Scenario: Missing or incorrect signature is rejected (existing, preserved)

- **GIVEN** the callback endpoint has a `dwangsom_callback_secret` configured
- **WHEN** a request arrives whose `X-Procest-Signature` header does not match the HMAC-SHA256 of
  the raw body under that secret
- **THEN** the system SHALL respond with HTTP 401 and MUST NOT process the payload

#### Scenario: Unconfigured secret fails closed (NEW)

- **GIVEN** the `dwangsom_callback_secret` app config value has never been set (empty string)
- **WHEN** any request — signed or unsigned — arrives at the payment-callback endpoint
- **THEN** the system SHALL respond with HTTP 401 and MUST NOT update any `DwangsomUitbetaling`
- **AND** the system SHALL log a `warning`-level entry (not `info`) so the missing configuration is
  operationally visible

#### Scenario: Admin can configure the secret (NEW)

- **GIVEN** an admin opens the dossiq admin settings page with the financial-integration
  capability enabled
- **WHEN** they view the dwangsom callback section
- **THEN** they SHALL see a field to set `dwangsom_callback_secret` (masked input) and a
  visible warning if it is currently unset

### Requirement: A case type's fee is published in shillinq, per intake channel (REQ-FEE-01)

A fee SHALL be published against a case type as a shillinq fee schedule,
carrying one amount per intake channel and the article of the
legesverordening the amount rests on. Raising the leges for a case SHALL
resolve the amount from that schedule for the case's own intake channel;
dossiq SHALL NOT declare an amount of its own. A schedule with no citation
SHALL be refused, and a case type with no published fee SHALL raise
nothing.

> Amended while building. The proposal put the fee list on the caseType.
> shillinq#1637 shipped the schedule first, keyed by register, schema and
> type value, with `amounts` per channel and a structured `legalBasis` it
> refuses to publish without. A second list of amounts here would be the
> two-sources-of-truth this change's own D-2 forbids for money, so dossiq
> consumes the schedule instead of restating it.

#### Scenario: the balie and the portal cost different amounts
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a published schedule with one amount for the portal and another for the balie
- **WHEN** a case is created through each
- **THEN** each SHALL raise a payment request for its own amount

#### Scenario: the citizen can be told which article
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case with a raised payment request
- **WHEN** a handler opens it
- **THEN** the article of the legesverordening SHALL be named

#### Scenario: a fee with no article is refused, not warned

- **GIVEN** a schedule declaring an amount and no citation
- **WHEN** it is published
- **THEN** publication SHALL be refused, naming what is missing

> Amended while building: shillinq refuses it outright
> (`FeeScheduleService::assertLegalBasis`). An amount nobody can trace to a
> council decision cannot be charged, and a warning is a thing that ships.

#### Scenario: a case type with no fee raises nothing
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case type with no published fee
- **WHEN** a case is created
- **THEN** no payment request SHALL be raised

### Requirement: The payment state is on the case and read from shillinq (REQ-FEE-02)

A case SHALL carry a payment state of not required, outstanding, paid or
waived, read from shillinq and held as a projection for listing and
filtering. dossiq SHALL NOT store an amount received, a ledger line or a
payment date as a source of truth. A projection that cannot be refreshed
SHALL read as stale and SHALL NOT read as paid.

A request shillinq reports as one it could not price, and a request this app
cannot parse, SHALL read as stale. Neither SHALL read as outstanding, as
waived or as paid: an amount nobody could read is not an amount that is owed,
not a fee anybody decided to let go, and not money that arrived. Where one
request is unpaid and another unreadable, the case SHALL read outstanding,
and the answer SHALL NOT depend on the order the requests were reported in.

#### Scenario: a handler sees whether the leges were paid
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case with an outstanding payment request
- **WHEN** a handler opens it and the case list
- **THEN** both SHALL show the payment state as outstanding

#### Scenario: dossiq holds no amount

- **GIVEN** a paid case
- **WHEN** the dossiq record is read
- **THEN** it SHALL carry the state
- **AND** it SHALL NOT carry an amount received

#### Scenario: an unrefreshable projection is stale, not paid

- **GIVEN** a case whose payment state cannot be refreshed from shillinq
- **WHEN** a handler opens it
- **THEN** the state SHALL read stale
- **AND** it SHALL NOT read paid

#### Scenario: a fee shillinq could not price is not a fee that is owed

@e2e exclude backend/data: needs a shillinq request whose stored amount is unreadable, and shillinq's write paths refuse to create one; asserted in CasePaymentStateTest

- **GIVEN** a case whose payment request shillinq reports as indeterminate
- **WHEN** the projection is derived
- **THEN** the state SHALL read stale
- **AND** it SHALL NOT read outstanding

#### Scenario: a request this app cannot parse is not a waiver

@e2e exclude backend/data: a malformed leaf item cannot be produced through the browser; asserted in CasePaymentStateTest

- **GIVEN** a case whose only payment request cannot be parsed
- **WHEN** the projection is derived
- **THEN** the state SHALL read stale
- **AND** it SHALL NOT read waived, which would open the gate and claim somebody let the money go

### Requirement: A named role sets the payment state by hand (REQ-FEE-03)

Money that arrived another way SHALL be recorded as an act, behind
shillinq's `payment.administer` permission, recording who, when and why,
and performed on the case through shillinq's payment panel. dossiq SHALL
offer no second way to set the state: the projection SHALL be read-only
everywhere it renders, and no dossiq surface SHALL make it an editable
field.

> Amended while building. The proposal gave dossiq its own override act
> behind its own role. shillinq#1637 shipped the settlement as an append
> behind `payment.administer`, so a dossiq act would be a second path to
> one financial fact, with a second rights matrix behind it and two
> records of who said the money arrived.

#### Scenario: cash at the balie is recorded

- **GIVEN** a case with an outstanding payment and a person holding `payment.administer`
- **WHEN** they record the counter payment with a reference
- **THEN** the case's state SHALL read paid at the next read
- **AND** who, when and why SHALL be recorded in shillinq

#### Scenario: dossiq offers no second way to set it

- **GIVEN** the payment state on a case
- **WHEN** any dossiq surface renders it
- **THEN** it SHALL be read-only
- **AND** no dossiq endpoint SHALL write it from a request body

### Requirement: A case type decides whether an unpaid case proceeds (REQ-FEE-04)

A case type SHALL declare whether a case may be handled before its payment
is settled. Where it may not, the acts that depend on payment SHALL be
refused with a 4xx carrying `{message, error}` naming the rule. Where the
payment state cannot be read and the case type requires payment first, the
act SHALL be refused rather than allowed. The refusal SHALL say the payment
record could not be read, and SHALL NOT name the payment service as
unreachable: shillinq answers that it could not price a request while it is
perfectly reachable, and a handler sent to check the network for a malformed
row loses an afternoon.

Only an outstanding payment and an unreadable state SHALL refuse. A case that
owes nothing, one that is paid and one whose fee was waived SHALL proceed:
refusing a citizen because we cannot read our own record is a different act
from refusing one who has not paid, and neither is a reason to hold a case
that is settled.

#### Scenario: an unpaid aanvraag waits
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case type requiring payment before handling, and an outstanding payment
- **WHEN** a handler moves the case on
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the payment rule

#### Scenario: a melding does not wait for money
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case type with no payment requirement
- **WHEN** a handler moves a case on
- **THEN** it SHALL proceed

#### Scenario: an unreadable state does not open the gate

- **GIVEN** a case type requiring payment, whose state cannot be read
- **WHEN** a handler moves the case on
- **THEN** it SHALL be refused
- **AND** the refusal SHALL say the payment record could not be read

### Requirement: A case names the contract it was raised under (REQ-FEE-05)

A case SHALL be able to reference a contract held in shillinq's contract
register. The contract SHALL be able to list the cases raised under it.
dossiq SHALL NOT copy the contract's term, its costs or its renewal date,
and SHALL NOT raise the alert before a contract lapses.

#### Scenario: a case raised under a raamovereenkomst says so
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a contract in shillinq's register
- **WHEN** a handler raises a case under it
- **THEN** the case SHALL name the contract

#### Scenario: the contract lists its cases

- **GIVEN** three cases raised under one contract
- **WHEN** the contract is read
- **THEN** all three SHALL be listed

#### Scenario: dossiq copies no contract term

- **GIVEN** a case referencing a contract
- **WHEN** the dossiq record is read
- **THEN** it SHALL carry the reference
- **AND** it SHALL NOT carry the contract's term, costs or renewal date
