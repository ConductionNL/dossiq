# Proposal: receipt-names-the-case-number

## Summary

The receipt in a resident's portal inbox names the case by its number, such as 2026-0117, in the title and as the kenmerk. It no longer shows the case UUID.

## Why

Live round 3 of the Zuiddrecht demo (14 Oct) showed "Ontvangstbevestiging zaak cecc92cc-6e45-..." in Sanne de Vries's inbox, with the same UUID as the kenmerk in the body. The case itself carried 2026-0117.

The acknowledgement service already hands the case number to the letter. The letter renderer read the term instance's case link first, and that link is the case UUID. Cases without a bound term showed the number, cases with one showed the UUID.

## What changes

- `TermLetters::render()` names the case by the kenmerk the caller passes, and falls back to the term's case link only when the caller passes none.
- A unit test drives `AcknowledgementService::acknowledge()` with a term whose case link is a UUID and asserts the title and the kenmerk carry the number.

## Out of scope

The information request and the pause reminder pass the case id as their `case`, so their letters still name the UUID. They need the case row to read its number and are a follow-up.
