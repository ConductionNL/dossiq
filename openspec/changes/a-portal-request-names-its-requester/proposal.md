---
kind: code
depends_on: []
---

# Proposal: a-portal-request-names-its-requester

## Why

Rehearsal on a fresh instance, 15 October (Woo request 2026-0013, filed on the portal by "Repetitie Inwoner"): the case page's Requester read "—". The name was on the case, in `wooRequest.verzoekerNaam` and in the case type's own `verzoekerNaam` property, but the Requester field shows the `requester` reference, which points at a row in a person or company register. A resident on the portal is no such row, so the reference stays empty and the field reads as if nobody asked.

## What changes

1. A Woo request filed through the intake writes the name the requester gave as the case's `initiatorDisplayName`, the projection the case list's Requester column already reads. A name the writer already set is kept.
2. The case page's Requester shows that name through a `requesterName` formatter. A picked register row writes the same projection, so a staff-created case reads as before.

## Not changed

- No person or company row is made for a portal resident; `requester` stays empty.
- Cases filed before this change keep an empty name until edited.

## Rollback

Revert the commit.
