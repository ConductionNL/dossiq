---
retrofit_extensions:
  - REQ-101
  - REQ-102
  - REQ-103
  - REQ-104
  - REQ-105
---

# Case Management — sharing + transfer + email + public access (retrofit)

## Requirements

### REQ-101: Procest SHALL expose case-sharing endpoints via CaseSharingController

`OCA\Procest\Controller\CaseSharingController` SHALL provide REST endpoints to create, revoke, list and inspect case shares — both token-based (anonymous URL with secret) and partner-based (named Nextcloud user/group). Endpoints SHALL delegate state changes to `CaseSharingService` and SHALL enforce that the calling user has write authority on the case being shared.

#### Scenario: Create token-based share
- **WHEN** a behandelaar POSTs `/api/case-shares` with `{caseId, type: 'token', permissions: ['read', 'comment'], expiresAt}`
- **THEN** the controller SHALL call `CaseSharingService::createTokenShare(...)`, which SHALL generate a cryptographically-random token via `generateToken()` and persist a Share record
- **AND** the response SHALL include the share URL with the token embedded

### REQ-102: CaseTransferService SHALL implement the case-transfer workflow

`OCA\Procest\Service\CaseTransferService` SHALL manage the explicit case-handover workflow: a behandelaar initiates a transfer to another user, the target user accepts or rejects, and on accept the case ownership is reassigned. Rejected transfers SHALL preserve the original owner and SHALL be recorded in the case audit trail with the rejection reason.

#### Scenario: Target user accepts a transfer
- **GIVEN** behandelaar A has called `initiateTransfer($caseId, userB, 'Going on leave')` creating transfer T
- **WHEN** user B calls `acceptTransfer(T.id)`
- **THEN** the case behandelaar SHALL be set to userB, the transfer record SHALL be marked accepted, and an audit-trail entry SHALL record both timestamps and the reason

#### Scenario: Target user rejects a transfer
- **WHEN** user B calls `rejectTransfer(T.id, 'Out of scope')`
- **THEN** the case behandelaar SHALL remain userA and the transfer record SHALL store the rejection reason

### REQ-103: Procest SHALL render and dispatch case emails via CaseEmailService

`OCA\Procest\Service\CaseEmailService` SHALL be the single entry point for sending case-context emails. It SHALL accept either a raw subject+body pair or a template id (resolving via the templates register), substitute case-payload variables via `resolveVariables()`, render the result, and dispatch via the underlying mail subsystem. Sent emails SHALL be persisted as an email record attached to the case so they appear in the case timeline.

The `OCA\Procest\Controller\EmailController` SHALL expose the HTTP surface: `POST /api/cases/{id}/email/send`, `POST /api/cases/{id}/email/sendFromTemplate`, and `GET /api/cases/{id}/email/preview` (which renders without sending so the user can review).

#### Scenario: Preview before send
- **WHEN** a behandelaar calls `EmailController::preview($caseId)` with `{templateId, recipient}`
- **THEN** the response SHALL contain the fully-rendered subject + body without any side effects

### REQ-104: PublicShareController SHALL enforce token-scoped access to case data

`OCA\Procest\Controller\PublicShareController` SHALL serve unauthenticated callers identifying themselves by share-token only. Each endpoint SHALL: (a) resolve the token via `CaseSharingService`, (b) reject if the share is expired or revoked, (c) restrict the operation to the permissions encoded on the share record (`read`, `comment`, `viewStatus`), and (d) NOT expose any case data outside the configured permissions.

#### Scenario: Read-only share cannot add a comment
- **GIVEN** an active share with permissions `['read']`
- **WHEN** the holder calls `addComment(token)`
- **THEN** the controller SHALL respond `403 Forbidden`

#### Scenario: Expired share is rejected
- **GIVEN** a share whose `expiresAt` is in the past
- **WHEN** any `PublicShareController` endpoint is called with its token
- **THEN** the controller SHALL respond `410 Gone` and the share SHALL NOT be auto-renewed

### REQ-105: ShareMaintenanceJob SHALL expire and prune stale shares

`OCA\Procest\BackgroundJob\ShareMaintenanceJob` SHALL run on the Nextcloud BackgroundJob schedule and: (a) mark token shares whose `expiresAt` is past as expired, (b) hard-delete shares older than the configured retention window, (c) close transfer records that have been pending past the configured timeout, returning the case ownership to the original behandelaar. The job SHALL be idempotent — re-running over a clean dataset SHALL be a no-op.

#### Scenario: Expire a token share
- **GIVEN** a Share with `expiresAt = now() - 1 hour` and status `active`
- **WHEN** `ShareMaintenanceJob::run()` executes
- **THEN** the Share SHALL be updated to status `expired`
