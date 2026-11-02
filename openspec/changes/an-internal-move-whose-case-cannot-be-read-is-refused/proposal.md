# Proposal: an-internal-move-whose-case-cannot-be-read-is-refused

## Why

A case type can declare that a move inside one organisation needs consent too (REQ-CST-01). The consent gate reads that rule from the case type. When the case or its case type could not be read, the gate answered "no consent needed" and let the move through. So a move that needed consent could go ahead with none, whenever the store, the register or a schema was missing or a read failed.

## What changes

- A move inside one organisation is refused when the case or its case type cannot be read.
- The refusal has the rule `consent-unchecked` and the sentence "Consent could not be checked, so the case was not handed on."
- That covers an empty case id, a case or case type that is not found, a read that fails, and a store, register or schema that is not configured.
- A case without a case type still moves without consent: no case type declares the rule.
- `consentRequiredInside()` answers true for an unreadable case, so consent counts as needed.
- A hand-off across organisations is unchanged.
