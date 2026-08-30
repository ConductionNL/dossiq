# Case Sharing and Collaboration

The case sharing and collaboration feature enables multiple users and organizations to work together on cases.

## Overview

Government case processing often requires collaboration between departments, organizations, or external parties. This feature provides the tools to share cases and collaborate securely, both within a single Nextcloud instance and across instances (federation).

## Shipped Features

- **Case sharing (same instance)** -- Share cases with a partner organization via `CaseSharingService::createPartnerShare()`, or mint a public "track your case" token link through OpenRegister's shares integration leaf.
- **Federated (cross-instance) case sharing** -- Share a redacted, field-scoped snapshot of a case with a remote organization over OpenRegister's OCM federation leaf (`FederationShareService`). Only explicitly selected fields (from a hard-coded, server-enforced allow-list: title, description, status, caseType, priority, dueDate, requestedDate) and document *references* attached to the case cross the boundary -- never the live case, never the whole object graph, never fields outside the allow-list. The remote organization gets **read-only** access to the snapshot; it cannot mutate the case.
- **Federated collaboration activity stream** -- An async, append-only activity stream scoped to one federated case share, postable by both the owning organization (local session) and the remote organization (authenticated via its scoped bearer token). This is asynchronous collaboration, not real-time co-editing (see "Not Yet Implemented" below).
- **Handoff workflows (zaakoverdracht)** -- Formally transfer case responsibility between organizations via `CaseTransferService` (initiate/accept/reject), now including **federated transfer across instances**: idempotent per (case, target organization, remote cloud ID), a custody audit trail on every state transition, and a dedicated transfer-scoped token so a remote organization's accept/reject can only ever change that one transfer's status -- never other fields, never a different transfer.
- **Role-based access** -- Permission-level slugs (view / comment / contribute) on same-instance partner shares.
- **Revocation** -- Revoking a federated share immediately invalidates its OpenRegister-minted token; every downstream check (the OR serving endpoint, the activity stream, transfer authentication) consults the same status.
- **Audit trail** -- Every cross-org federation action (share create/revoke, activity post from either side, transfer initiate/accept/reject) is logged via `TenantAuditTrailService`.

## Not Yet Implemented / Open Questions

- **Document *content* federation** -- Only document *references* (id + filename) are federated as part of a case-summary snapshot; the actual file bytes are not transferred. Real cross-instance file access would ride Nextcloud's `federatedfilesharing`/OCM webdav layer, which this feature does not implement.
- **Real-time document co-editing** -- Not implemented. The shipped surface is an async activity stream, not collaborative document editing.
- **Live cross-instance verification** -- This feature's federated paths (OCM `shareReceived()` round-trip, a remote peer without OpenRegister installed) have not been verified against a second, live Nextcloud instance in this environment. See the `federated-case-collaboration` change's design doc for the full list of open questions.

## Status

Same-instance sharing/transfer is defined in `openspec/specs/retrofit-2026-05-24-case-management` (retroactively specified). Federated (cross-instance) sharing, the collaboration activity stream, and federated transfer are defined in `openspec/specs/federated-case-collaboration/spec.md`.
