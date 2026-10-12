---
kind: code
depends_on: [intake-says-when-the-term-starts, one-term-engine]
---

# Proposal: receipt-and-start-dates-are-set-at-creation

Ruben's decision of 10 October 2026: the receipt date (`receiptDate`,
ontvangstdatum) and the case start date (`startDate`) are not set by the
create form. dossiq sets them when the case is created, through the create
flow it already runs on every new case.

## Why

The New case form asks a handler for a start date, and the Woo case type
asks for an ontvangstdatum as a required question. Both are facts about when
the request came in, and dossiq already knows that moment: the term engine
stamps `receivedAt` and `termStartsAt` on every new case
(`intake-says-when-the-term-starts`, REQ-TERM-040) and starts the statutory
term from it (`one-term-engine`, REQ-OTE-02). Asking a handler to type the
same fact again gives two answers to one question, and nothing in PHP reads
the Woo answer, so the term never used what the handler typed.

## What changes

- On creation, dossiq writes `startDate` (the receipt date) and, for a case
  type that declares a `receiptDate` question, that question's answer (the
  receipt date), each only when the case does not already carry one.
- The receipt is, in order: a `receivedAt` moment the creating path supplied
  (a channel message, a DSO submission), a `receiptDate` answer an
  integration supplied, then the existing fallbacks, then the creation
  moment. The statutory term starts from that same receipt.
- The DSO and channel intake paths write the received moment they already
  hold as `receivedAt`, so the term starts at the submission, not at the
  moment the case row was written.
- A receipt known only to the day (an imported `receiptDate`) is flagged
  outside working hours only when its term starts on a later day.
- The Woo `receiptDate` question is no longer required: it was required only
  so the form would ask it.
- The create dialogs stop asking for `startDate` (manifest change, made by
  the frontend lane).

## Out of scope

- A per case type rule for the start date. Ruben decided on 11 October 2026
  that the start date is always the receipt date, for every case type, with
  no per case type rule. No case type field states one, and none is to be
  added. Amended by a-request-form-opens-the-case-at-once (decision 181,
  Q11): when the term starts on a later day than the request came in, the
  start date is the day the term starts. That is still one rule for every
  case type.
- Hiding a case type's own question from the create form. The
  `x-openregister-extends-form` declaration renders every definition of the
  chosen type, and nextcloud-vue offers no way to keep one off the form.
  Since nextcloud-vue 2.79.0 it does (`hideOnCreate`); the Woo
  `receiptDate` question takes it in `adopt-nextcloud-vue-2-79`.
