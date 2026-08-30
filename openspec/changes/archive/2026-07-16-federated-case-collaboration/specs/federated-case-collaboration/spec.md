# federated-case-collaboration Specification

## Purpose
Extend procest's existing in-instance case sharing/transfer (`CaseSharingService::createPartnerShare`, `CaseTransferService`) to work across Nextcloud instances over OpenRegister's OCM federation leaf, closing the "Planned" cross-organization sharing, activity feed and handoff-workflow items in `docs/Features/case-sharing-collaboration.md`. Procest owns case-domain semantics (what crosses the boundary, authority, custody); OpenRegister's `FederationShareService`/`FederatedShareMapper`/`OpenRegisterCloudFederationProvider` own OCM transport, token minting, and revocation-status storage.

## ADDED Requirements

### Requirement: Federated Case Share Is A Redacted Snapshot, Never The Live Case

Procest SHALL create a federated case share by building a purpose-made `caseFederatedShare` object containing only fields from a hard-coded allow-list and document references actually attached to the case, and SHALL share that object — never the live case object — through OpenRegister's `FederationShareService::createOutgoingShare()` with `permissions: 'read'`. A field or document not explicitly selected, or a field outside the allow-list, SHALL NOT cross the boundary. Requesting a disallowed field SHALL fail the whole request with an explicit error, not silently drop the field.

#### Scenario: Creating a federated share includes only allow-listed fields

- **GIVEN** a case with fields `title`, `description`, `status`, and a sensitive internal field `internalRiskScore`
- **WHEN** a handler creates a federated share selecting `title` and `status`
- **THEN** the persisted `caseFederatedShare.fieldSnapshot` SHALL contain exactly `title` and `status`
- **AND** `internalRiskScore` SHALL NOT appear anywhere in the snapshot or the OR-federation-served response

#### Scenario: Requesting a disallowed field fails loudly

- **GIVEN** a case and a handler with access to it
- **WHEN** the handler requests a federated share including a field not on `FEDERATION_ALLOWED_FIELDS`
- **THEN** the request SHALL fail with an explicit error
- **AND** no `caseFederatedShare` object or OR `FederatedShare` SHALL be created

#### Scenario: The relations mirror is never exposed on a federated payload

- **GIVEN** a case whose OR object carries `@self.relations`
- **WHEN** a federated share is created, even if `@self` or `relations` is included in the requested field list
- **THEN** the resulting snapshot SHALL NOT contain an `@self` or `relations` key

@e2e exclude Field-scoping and redaction are asserted by PHPUnit against `CaseSharingService::createFederatedShare()` with a mocked OR `ObjectService`/`FederationShareService` container boundary — no live second Nextcloud instance is available to drive this cross-instance, so end-to-end browser coverage is not possible in this change (see design.md Open Questions).

---

### Requirement: Federated Share Revocation Is Immediate And Single-Sourced

Procest SHALL revoke a federated case share by setting the underlying OpenRegister `FederatedShare.status` to `revoked` via `FederationShareService::setStatus()`. Every downstream check (OR's own federation-object read endpoint, procest's transfer-token resolution, procest's remote-activity token resolution) SHALL consult that same status column, so revocation takes effect immediately across all surfaces without a separate procest-side flag to keep in sync.

#### Scenario: A revoked federated share can no longer authenticate any remote action

- **GIVEN** an accepted federated case share with an active OR `FederatedShare`
- **WHEN** the owning org revokes the share
- **THEN** the OR `FederatedShare.status` SHALL become `revoked`
- **AND** a subsequent remote request bearing that share's token SHALL be rejected by procest's own token-resolution checks

@e2e exclude Revocation-status propagation is a same-process check (one DB row read by two code paths), verified by PHPUnit; no distinct browser UI surface beyond the existing "Revoke" action already covered by the wired sharing tab.

---

### Requirement: Federated Sharing Fails Closed When The OR Federation Leaf Is Unavailable

Unlike `canUserAccessCase()` (which fails open when OpenRegister is unavailable, since there is nothing to protect on a same-instance-only setup), every federated-sharing, federated-activity and federated-transfer method SHALL fail closed — return an explicit error and perform no write — when OpenRegister or its federation classes (`FederationShareService`, `FederatedShareMapper`) are unavailable.

#### Scenario: Federated share creation refuses to proceed without the OR federation leaf

- **GIVEN** OpenRegister is not installed, or an older OpenRegister without the federation classes is installed
- **WHEN** a handler attempts to create a federated case share
- **THEN** the call SHALL return an error
- **AND** no `caseFederatedShare` object SHALL be persisted

@e2e exclude Dependency-unavailability is a same-process container-resolution check, verified by PHPUnit with the container mocked to throw/return null for the federation service classes.

---

### Requirement: Shared Activity Stream Is Async, Append-Only, Scoped To One Federated Share

Procest SHALL provide a collaboration activity stream (`caseFederatedActivity`) scoped to exactly one federated case share, postable by the owning org (local session, authorized via case access) and by the remote org (authenticated via the federated share's scoped bearer token). Entries SHALL be append-only — an actor SHALL NOT be able to edit or delete another actor's entry. Real-time co-editing SHALL NOT be implemented or implied by this stream; it is documented as async collaboration.

#### Scenario: A local handler posts an activity entry

- **GIVEN** an active federated case share
- **WHEN** the owning-org handler (who has case access) posts an activity message
- **THEN** an entry with `actorType: 'local'` SHALL be appended to the share's activity stream

#### Scenario: A remote org posts an activity entry via its scoped token

- **GIVEN** an active federated case share with a valid, non-revoked bearer token
- **WHEN** a request bearing that token posts an activity message
- **THEN** an entry with `actorType: 'remote'` and the token's `sharedWith` cloud id SHALL be appended

#### Scenario: A revoked or mismatched token cannot post activity

- **GIVEN** a federated case share that has been revoked, or a token minted for a different share
- **WHEN** a request bearing that token attempts to post an activity entry
- **THEN** the request SHALL be rejected
- **AND** no entry SHALL be appended

@e2e exclude Remote-token authentication is verified by PHPUnit against a mocked `FederatedShareMapper`; the local-post path is covered by the wired case-detail "Sharing" tab, and its API-call wiring is covered by Vitest. No live second instance is available to exercise a genuine cross-instance POST.

---

### Requirement: Case Transfer Extends Across Federation With Idempotent Accept/Reject And A Custody Audit Trail

`CaseTransferService::initiateTransfer` SHALL accept an optional `remoteCloudId`. When set, the service SHALL mint a transfer-scoped OR federated share (`permissions: read-write`, pointed at the transfer object only) used exclusively to authenticate the remote accept/reject call, and SHALL be idempotent per `(caseId, targetOrganization, remoteCloudId)` — a duplicate initiate SHALL return the existing pending/accepted transfer rather than creating a second one. `acceptTransfer`/`rejectTransfer` SHALL append a `custodyAuditTrail` entry on every transition, SHALL be idempotent on a repeated call matching the already-reached status, and SHALL refuse (with an explicit error) any call that would resolve an ambiguous state (e.g. accepting an already-rejected transfer).

#### Scenario: Initiating the same federated transfer twice is idempotent

- **GIVEN** a case, a target organization, and a remote cloud id
- **WHEN** `initiateTransfer` is called twice with the same case/target/remoteCloudId
- **THEN** the second call SHALL return the existing transfer
- **AND** no second `casetransfer` object SHALL be created

#### Scenario: A remote org accepts a transfer addressed to it via its scoped token

- **GIVEN** a pending federated transfer with a valid `read-write` transfer-scoped token
- **WHEN** the remote org calls the transfer accept endpoint bearing that token
- **THEN** the transfer status SHALL become `accepted`
- **AND** a `custodyAuditTrail` entry with `actorType: 'remote'` SHALL be appended

#### Scenario: A read-only case-share token cannot accept a transfer

- **GIVEN** a federated case-summary share token (`permissions: 'read'`)
- **WHEN** that token is used to call the transfer accept endpoint
- **THEN** the request SHALL be rejected
- **AND** the transfer status SHALL remain unchanged

#### Scenario: Accepting an already-rejected transfer is refused loudly

- **GIVEN** a transfer whose status is `rejected`
- **WHEN** an accept call is made against it
- **THEN** the call SHALL return an explicit error
- **AND** the transfer status SHALL remain `rejected`

#### Scenario: Repeating an accept call after it already succeeded is a safe no-op

- **GIVEN** a transfer whose status is already `accepted`
- **WHEN** an accept call is made again with the same remote actor
- **THEN** the call SHALL return the existing transfer data unchanged
- **AND** no duplicate `custodyAuditTrail` entry SHALL be appended

#### Scenario: Local transfer accept/reject requires case access (pre-existing gap fix)

- **GIVEN** a pending transfer for a case the caller cannot access
- **WHEN** the caller calls the local (session-authenticated) accept/reject endpoint
- **THEN** the request SHALL be rejected with a 403

@e2e exclude Transfer state-machine, idempotency and token-scope checks are verified by PHPUnit against mocked OR services; the remote accept/reject path has a public Vue page (`PublicFederatedTransferPage.vue`) but its correctness against a genuine second Nextcloud instance is an Open Question (design.md) — no live second instance is available in this environment.

---

### Requirement: Every Cross-Org Federation Action Is Audited

Every federated-sharing, federated-activity and federated-transfer action that crosses the org boundary (share create/revoke, activity post from either side, transfer initiate/accept/reject) SHALL emit a structured audit entry via `TenantAuditTrailService`.

#### Scenario: Creating a federated share emits an audit entry

- **GIVEN** a valid federated-share request
- **WHEN** the share is created
- **THEN** a `TenantAuditTrailService` entry with `action: 'federated_case_share_created'` and the acting user SHALL be emitted

@e2e exclude Audit emission is a same-process side effect verified by PHPUnit asserting the audit service is called with the expected payload.

---

### Requirement: The Case-Detail Sharing Surface Is Wired, Not Orphaned

The case-detail sidebar SHALL expose a "Sharing" tab that mounts the partner-share, federated-share, transfer, and collaboration-activity UI, reachable from the real case detail page — not merely present as unreferenced component files.

#### Scenario: The Sharing tab is reachable from a case detail page

- **GIVEN** a user viewing a case's detail page
- **WHEN** they open the case-detail sidebar
- **THEN** a "Sharing" tab SHALL be present
- **AND** selecting it SHALL render the partner/federated share list and the transfer/activity actions

@e2e exclude Verifying the tab renders end-to-end requires a running Nextcloud UI session; this change verifies the wiring statically (registry.js/manifest.json entries + Vitest coverage of the container component's API calls), consistent with how other sidebar-tab leaves in this codebase are verified (see `case-share-via-shares-leaf` spec precedent).
