# Proposal: migrate-public-share-to-shares-leaf

## Why

Procest ships a bespoke token-sharing mechanism: `PublicShareController` serves unauthenticated
access to a shared case via a generated token, and `CreateShareDialog.vue` (with `ShareTab.vue`)
mints those share links from the case detail page. This re-implements share-link creation, token
generation, and public-access gating in-app.

OpenRegister provides a **shares** integration leaf (ADR-019). The leaf creates, lists, and revokes
share links for an OR object, with token generation, expiry, and public-access resolution owned by
the leaf + OR's standard mechanism. Procest's in-app token sharing is a direct **ADR-022** violation
(Apps Consume OpenRegister Abstractions):

- **Duplicate token mechanism**: procest generates and validates share tokens that the shares leaf
  already provides and tests.
- **Drift + security surface**: a parallel public-access controller is an independent auth surface
  to maintain (token entropy, expiry, revocation, IDOR risk) instead of consuming the audited leaf.
- **No cross-app share inventory**: links minted in-app can't appear in a fleet-wide shares view.

## What

This change replaces procest's in-app token sharing with the OR shares leaf on the `case` detail
page:

1. The `CreateShareDialog.vue` "Share link" (token) path is replaced by the shares leaf tab/widget;
   the case detail page surfaces share creation/listing/revocation through the leaf.
2. `PublicShareController` token resolution is replaced by the shares leaf's public-access path.
   The procest public case-view route is either removed or thinned to delegate to the leaf's
   resolution (decided in design.md once the leaf's public surface is confirmed).
3. `CreateShareDialog.vue` and `ShareTab.vue` are removed (or reduced to a non-token concern — see
   the partner-organisation note below).

### Partner-organisation sharing note

`CreateShareDialog.vue` also offers a "Partner organization" share type that is NOT token-based
public sharing — it is an org-to-org case-handover concern. This change migrates ONLY the **token
public-share** path to the shares leaf. If the partner-organisation path is zaak-domain handover
logic (not a generic share), it stays in-app and is flagged in design.md as out-of-scope for this
leaf migration.

## Capabilities

### New Capabilities

- `case-share-via-shares-leaf`: Public token sharing of a case is created, listed, and revoked
  through OR's shares integration leaf; procest mints no share tokens of its own.

### Modified Capabilities

- (no existing org-wide spec changes requirements; procest has no canonical `public-share` spec —
  the token sharing lives only in code. This change establishes the consumer contract.)

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo
- [x] Project: `openregister` — no code change; the shares leaf is consumed, not modified

## Out of Scope

- The shares leaf's own implementation in OR.
- Partner-organisation case handover (zaak-domain logic, not generic sharing) — stays in-app.
- Migration of historical share tokens already minted by `PublicShareController` (sunset window;
  confirm in design.md whether old tokens must keep resolving).
- The citizen status page content itself (only the share/token gating moves to the leaf).

## Success Criteria

- `openspec validate migrate-public-share-to-shares-leaf --strict` exits 0.
- The case detail page creates/lists/revokes public share links through the shares leaf.
- `PublicShareController` token path and `CreateShareDialog.vue` token path are removed.
