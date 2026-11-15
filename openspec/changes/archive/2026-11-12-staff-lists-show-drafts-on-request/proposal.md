---
kind: code
depends_on: []
---

# Proposal: staff-lists-show-drafts-on-request

## Summary

The Cases list gets one chip, With drafts, that shows drafts beside the open cases. Without it a colleague's draft is in no staff list.

- Decision: Ruben, 2026-10-11, decision 201. "Staff work lists hide drafts by default (filter to show)."
- Counterpart: `openregister/form-destination-validator`, "A list must hide drafts unless asked". OpenRegister leaves drafts out of every list, count and search unless the query carries `_drafts`.

## Why

A draft is the case itself in the status draft (decision 180). Since OpenRegister hides drafts from every list, a handler who wants to see what is on its way has no way to ask. My drafts shows your own drafts only, through dossiq's older `isDraft` marker.

## What changes

1. The Cases page declares a chip With drafts whose filter is the open cases plus `_drafts: "any"`. It sits behind the overflow chip, beside My drafts (REQ-CM-79).
2. No other lens and no other staff list carries a `_drafts` condition. The exclusion is the platform's default and is spelled out nowhere in dossiq.

## Out of scope

- Who may read a draft. The chip widens what the list shows, never what the reader may read (OpenRegister `DraftStatusPolicy`).
- The Voorlopig label on a draft row and the Definitief maken action (decision 200).
- Moving My drafts from `isDraft` to the stored status.

## Release notes

- On Cases, the chip With drafts shows drafts among the open cases. Every other list leaves drafts out.
