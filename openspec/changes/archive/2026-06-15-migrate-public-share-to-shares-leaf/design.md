# Design: migrate-public-share-to-shares-leaf

## Context

OR's shares integration leaf (ADR-019) contributes a shares tab + widget on an OR object's detail
page. It creates share links with token generation, optional expiry, and revocation, and resolves
public access through OR's standard public-share path. Procest consumes the leaf; it does not mint
tokens or run its own public-access controller for the migrated path.

## File-by-File Mapping

| Existing procest artifact | Disposition |
|---|---|
| `lib/Controller/PublicShareController.php` | Token-resolution path removed/thinned — public access resolves via the shares leaf. Confirm whether a thin redirect/delegation route remains |
| `src/views/cases/components/CreateShareDialog.vue` | Token "Share link" path removed; if the "Partner organization" path stays, the dialog is reduced to that concern only |
| `src/views/cases/components/ShareTab.vue` | Token-share UI replaced by the shares leaf tab; removed if it only hosted token sharing |

## What moves vs what stays

- **Moves to the leaf**: share-link creation, token generation, listing, revocation, public-access
  resolution for the case.
- **Stays in procest (flagged out-of-scope)**: the "Partner organization" handover path, if it is
  zaak-domain org-to-org logic rather than a generic share token.
- **Decision needed**: whether historical tokens minted by `PublicShareController` must keep
  resolving during a sunset window (legacy-compat read path) or are dropped at cutover.

## Security note

Removing the bespoke public controller removes a hand-maintained auth surface (token entropy,
expiry, revocation, IDOR on case IDs). The shares leaf's audited public-access path is the
ADR-022-preferred mechanism; this migration is a net security improvement, not just code removal.

## DEFERRED_QUESTIONS

- Confirm the OR shares leaf `id` and the pinned `@conduction/nextcloud-vue` version shipping it.
- Confirm the shares leaf's public-access resolution surface (route shape) so `PublicShareController`
  can be removed or thinned to a delegation.
- DECISION: must legacy `PublicShareController` tokens keep resolving during a sunset window?
- Confirm whether the "Partner organization" share type is zaak-domain handover (stays in-app) or a
  generic share (also leaf-able).
