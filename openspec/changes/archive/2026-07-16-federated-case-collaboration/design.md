# Design — federated-case-collaboration

## 1. OR-primitive-vs-procest-semantics decision

**Decision: reuse OpenRegister's federation leaf entirely for transport/tokens/OCM; add zero code to OpenRegister.**

Read against HEAD (`origin/development` in `openregister`), OR already ships:
- `Service\FederationShareService` — create outgoing shares (`scope: register|schema|object|query`, `permissions: read|read-write`), record incoming shares, list, `setStatus()` (accept/decline/revoke).
- `Db\FederatedShare` / `Db\FederatedShareMapper` — persistence, `findByToken()` (the token IS the credential — no organisation filter applied, by design, since the caller is a remote instance).
- `Federation\OpenRegisterCloudFederationProvider` — `OCP\Federation\ICloudFederationProvider` implementation registered for OCM resource type `openregister`; handles `shareReceived()` / `notificationReceived()`.
- `Controller\FederationController` — `#[PublicPage]` serving endpoints: `objects()`, `object()`, `createObject()`, `updateObject()`, `deleteObject()`, `meta()`, all resolved via the scoped bearer token, all pinned to the sharing organisation, all denying revoked/declined shares.

Two real gaps exist, and both are handled *without* touching OR:

1. **No field-level projection.** `FederationController::applyShareVisibility()` serves "exactly the object the sharer chose" for `scope: object` — the whole object, not a redacted subset. The brief requires "case summary + explicitly-selected fields/documents, NEVER the whole object graph by default". Rather than teaching OR's generic object-federation about per-share field allow-lists (which would leak the concept of "case" into a schema-agnostic leaf), procest builds a **purpose-made snapshot object** (`caseFederatedShare`) containing only the allow-listed fields + validated document refs, and shares *that* object via OR's existing `scope: object` mechanism. OR's federation code is completely unaware anything was redacted — it just serves the object it was pointed at, which happens to already be the redacted view. This is the same pattern procest already uses for the public case-token share (`CaseTokenService`) — a purpose-built object stands in for the raw case.
2. **No workflow/state-machine semantics.** A case *transfer* is not "read/write an object" — accepting is a validated state transition (`pending -> accepted`, once, idempotently, with an audit trail), not "let the remote PUT arbitrary fields". `FederationController::updateObject()` is a raw CRUD passthrough: if procest let the remote accept a transfer by PUTing to that endpoint, the remote could also silently rewrite `caseId`/`targetOrganization`/`reason` in the same request. So procest does **not** use `updateObject()` for transfers. Instead, procest mints its own OR-backed scoped share (`scope: object`, `permissions: read-write`, pointed at the `casetransfer` object) purely so **procest's own `#[PublicPage]` endpoint** can authenticate the remote caller by resolving that token via `FederatedShareMapper::findByToken()` (reusing OR's token issuance/lookup/revocation — the actual "leaf" primitive) — and then procest's own `CaseTransferService::acceptTransfer()/rejectTransfer()` state machine runs, which only ever touches `status`/`completedAt`/`custodyAuditTrail`.

Net effect: OR's leaf owns token minting, OCM transport/registration, revocation-status storage, and (for the case-summary read path only) the actual object-serving endpoint. Procest owns: what data goes into the shared object in the first place, the case/transfer domain semantics, and the collaboration-activity read/write paths.

If a live second instance later reveals OR's `updateObject()` needs a "field allow-list" concept as a first-class feature (e.g. because more apps hit the same wall), that becomes a follow-up OR PR — not needed for this change since procest routes around it by construction.

## 2. What crosses the boundary

`CaseSharingService::createFederatedShare()`:
1. Loads the case via `canUserAccessCase()`-gated `ObjectService::find()` (the controller already enforces this before calling in).
2. Intersects the caller's requested `sharedFields` against `CaseSharingService::FEDERATION_ALLOWED_FIELDS` (a hard-coded allow-list of non-sensitive case-summary fields: `title`, `description`, `status`, `caseType`, `priority`, `dueDate`, `requestedDate`). Any requested field **outside** the allow-list is a hard error (`['error' => 'Field "..." is not shareable across a federation boundary']`) — never a silent drop. `@self` and `relations` are never in the allow-list and are explicitly rejected even if requested, closing the same class of leak the fleet lesson flagged for `@self.relations` on the relations mirror.
3. Intersects `sharedDocuments` against the case's own `documents` array (if present) — a document id that is not actually attached to the case is rejected, not silently dropped.
4. Builds `fieldSnapshot` = exactly the allow-listed key/value pairs present on the case at share time (a **snapshot**, not a live view — see Open Questions).
5. Saves a `caseFederatedShare` OR object (`caseId`, `remoteCloudId`, `sharedFields`, `sharedDocuments`, `fieldSnapshot`, `permissionLevel`, `createdBy`, `status: 'pending'`).
6. Calls OR's `FederationShareService::createOutgoingShare(scope: 'object', register: <procest register>, schema: <caseFederatedShare schema>, objectUri: <the new object's uuid>, sharedWith: remoteCloudId, permissions: 'read')` — **always `read`**, never `read-write`, for the case-summary share.
7. Persists the returned `FederatedShare` id back onto the `caseFederatedShare` object as `federationShareId`, and emits a `TenantAuditTrailService` entry (`action: federated_case_share_created`).

The remote org's own OpenRegister-federation-aware client can then `GET` the case summary straight through OR's existing `federation/object/{token}/{id}` endpoint — zero new procest server code needed for that specific read, because the object it resolves to is already the redacted snapshot.

## 3. Authority model

- **The owning org never loses authority over the live case.** The case-summary share is always `read`. Nothing in this change lets a remote org mutate the case object, its notes, or any field procest didn't explicitly put in the snapshot.
- **Custody only changes via an explicit, audited transfer accept.** Sharing a case (even a federated one) is not a custody change — it's collaboration. Only `CaseTransferService::acceptTransfer()` reassigns responsibility, and it does so as one atomic state transition with a `custodyAuditTrail` entry, never implicitly.
- **The remote org's write surface is exactly two things**: (a) posting activity entries on a federated case share's collaboration stream (append-only — a remote actor can add an entry, never edit or delete another actor's entry), and (b) accepting/rejecting a transfer addressed to them (and only that transfer's `status`, via procest's own state machine, never arbitrary fields).
- **Revocation is immediate and single-sourced.** `CaseSharingService::revokeFederatedShare()` calls OR's `FederationShareService::setStatus($federationShareId, 'revoked')`. Every check downstream — OR's own `resolveAcceptedShare()` for the case-summary read, and procest's own `resolveFederatedTransferShare()` / `CaseCollaborationService`'s remote-token check — reads that same `FederatedShare.status` column, so revocation cannot be "revoked in procest but still resolvable via the OR endpoint" or vice versa.
- **Fail-closed, not fail-open, once a request has crossed an org boundary.** The existing `canUserAccessCase()` deliberately fails *open* when OR is unavailable (a local, same-instance convenience for basic setups — there's nothing to protect if OR itself is down for everyone). Federation is different: if OR's federation classes are unavailable (older OR version, or the app simply isn't there), every federated method returns `['error' => 'Federated case sharing requires the OpenRegister federation leaf']` and no object is created, no token is honoured. A missing dependency must never silently downgrade to "share anyway" or "trust anyway".

## 4. Transfer-over-federation design

`initiateTransfer(caseId, sourceOrganization, targetOrganization, reason, requestedDate, initiatedBy: '', remoteCloudId: null)`:
- When `remoteCloudId` is set: computes `idempotencyKey = hash('sha256', caseId . '|' . targetOrganization . '|' . remoteCloudId)`. If a transfer with that key already exists in `pending`/`accepted` state, **returns the existing transfer** instead of creating a duplicate (idempotent by construction, not by luck).
- Seeds `custodyAuditTrail` with one `{event: 'initiated', actor: initiatedBy, actorType: 'local', timestamp}` entry.
- Mints a fresh OR `FederatedShare` (`scope: object`, `permissions: read-write`, `objectUri: <transfer uuid>`, `sharedWith: remoteCloudId`) and stores its id as `federationShareId` on the transfer object. This is a *transfer-scoped* token — it authenticates "this remote is the addressed counterparty for this one transfer", nothing else; it is not reused for case-summary reads or activity.

`resolveFederatedTransferShare(shareToken, transferId)` (used only by the new `#[PublicPage]` remote endpoint): resolves the token via `FederatedShareMapper::findByToken()`, and requires **all** of: direction `outgoing`, status not `revoked`/`declined`, permissions `read-write`, and the share's `objectUri` tail matches `transferId` exactly. Any mismatch returns `null` (403), including the case where a token minted for transfer A is replayed against transfer B.

`acceptTransfer($transferId, remoteCloudId=null)` / `rejectTransfer($transferId, $reason, remoteCloudId=null)`:
- If `status === 'pending'`: apply the transition, append a `custodyAuditTrail` entry (`actorType: remoteCloudId !== null ? 'remote' : 'local'`, `cloudId: remoteCloudId`), audit-log it.
- If `status` already equals the **target** status (e.g. accept called twice) — this is an **idempotent replay**: return the existing data unchanged, no error, no duplicate audit entry.
- If `status` is anything else non-pending (e.g. accept called after reject, or after a prior accept) — **refuse loudly**: `['error' => 'Transfer is not in a state that can be accepted/rejected']`. Ambiguous state is never silently resolved either way.
- The pre-existing local `handleTransfer()` controller endpoint had **no** authorization guard at all (any authenticated user could accept/reject any transfer by guessing/enumerating its UUID) — a pre-existing gap directly in the code this change extends. Fixed alongside: local accept/reject now requires `canUserAccessCase()` on the transfer's `caseId`, mirroring the guard `initiateTransfer()` already had.

## 5. Security proofs (see tests)

- Share payload scoping: a field outside `FEDERATION_ALLOWED_FIELDS` is rejected outright; the resulting snapshot never contains `@self` or `relations` even when maliciously requested.
- Authority model: a remote bearer token minted for case-summary read (`permissions: read`) is rejected by the transfer-accept path (which requires `read-write` AND an exact `objectUri` match) — a read-only case-share token can never accept a transfer.
- Transfer accept/reject: idempotent replay returns the same result twice; a conflicting second call (reject after accept) is refused loudly; custody audit entries accumulate correctly.
- Revocation: once `setStatus(..., 'revoked')` is called, `resolveFederatedTransferShare()` and the collaboration remote-token check both immediately return null/reject.
- Fail-closed: with the OR federation classes unavailable (container throws / class doesn't exist), every federated method returns an error and performs no writes — verified by asserting zero calls reach the mocked `ObjectService::saveObject()`.

## 6. What's real vs. Open Question

**Real, verified by PHPUnit + Vitest + code review in this change:**
- Field-scoped share creation, allow-list enforcement, snapshot redaction.
- Revocation semantics against a mocked OR container boundary.
- Transfer idempotency, custody audit trail, state-machine refusal of ambiguous transitions.
- Activity post/list for both local (session-authenticated) and remote (token-authenticated) actors, against a mocked `FederatedShareMapper`.
- UI wiring: the "Sharing" case-detail sidebar tab, its dialogs, and the public federated-transfer accept/reject page are all mounted and reachable — Vitest covers the container component's API-call wiring.

**Open Question — NOT verified in this change (no second live Nextcloud instance available, mirroring how the openconnector adapters flagged their transport gaps):**
- Whether OR's OCM `shareReceived()` round-trip actually delivers a usable `apiUrl`/token pair end-to-end between two live instances with `federatedfilesharing`/`federation` NC apps enabled, and whether a remote instance without OpenRegister installed can meaningfully participate at all (the case-summary read path assumes the remote peer speaks OR's federation-object JSON shape).
- Document *content* federation (file bytes, not just refs) — this change federates document **references** (id + filename) as part of the snapshot, not file content. Real cross-instance file access would ride NC's `federatedfilesharing`/OCM webdav layer, which is unexercised here.
- True real-time co-editing of a shared case — explicitly out of scope; the shipped surface is async append-only activity entries, not live collaborative editing.
- Whether OR's `listShares()` (used only by OR's own admin UI, not called by procest in this change) exposes the raw `shareToken` in listing responses is an OR-side question procest does not depend on, since procest talks to `FederationShareService`/`FederatedShareMapper` directly via the DI container rather than through OR's HTTP controller.

## 7. Fixing the pre-existing orphan

`ShareTab.vue`, `CreateShareDialog.vue`, `CaseTransferDialog.vue` had zero references anywhere outside their own files before this change (verified: `grep -rln` across `src/` other than the files themselves returned nothing). The routes behind them (`POST /api/shares`, `DELETE /api/shares/{id}`, `POST /api/transfers`, `PUT /api/transfers/{id}`) were live but unreachable from any real UI — a textbook orphaned capability. This change adds the missing container component (`CaseSharingTab.vue`), wires it as a `component:` sidebar tab on `CaseDetail` (mirroring the existing `CaseNotesTab`/`VersionHistoryLeafTab` pattern in `src/registry.js` + `src/manifest.json`), and extends the three existing components rather than leaving them dead. The new federated-share/activity/transfer UI is added to the same wired surface, so it does not become a fourth orphan.
