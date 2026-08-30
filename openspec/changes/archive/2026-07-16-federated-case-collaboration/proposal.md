# Federated case collaboration

## Why
A wave-2 audit found cross-organisation case handling is only **partial**: `CaseSharingService::createPartnerShare()` and `CaseTransferService` (initiate/accept/reject) already implement org-to-org handoff, but they only work between organisations on the *same* Nextcloud instance (a `partnerOrganization` object + an NC group). Procest's own docs (`docs/Features/case-sharing-collaboration.md`) list **cross-organization sharing** (federated instances), **document co-editing** and an **activity feed** as "Planned" — this is the last capability in the workup marked "partial" rather than "done" or "not started".

Nextcloud Hub 26 Winter shipped federated Deck boards, calendar federation and cross-org Teams on top of existing NC identities — proving OCM federation is now a viable foundation for inter-municipality *zaakoverdracht* (case transfer), and cross-municipal data sharing is a recognised real-world need (e.g. joint environmental enforcement across municipal borders).

OpenRegister already ships a generic cross-instance federation primitive (`FederationShareService`, `FederatedShareMapper`, `OpenRegisterCloudFederationProvider` implementing `OCP\Federation\ICloudFederationProvider`, `FederationController`) — registers/schemas/objects/queries can be shared with a remote org over OCM, with a scoped bearer token and read/read-write permissions. It does **not** know anything about cases, does **not** do field-level projection (an object-scope share serves the *whole* object it was pointed at), and has no concept of "transfer" as a state machine. This change makes procest **consume** that leaf rather than reimplement OCM/federation transport, and adds the case-domain semantics on top: what crosses the boundary, who has authority, and how custody changes hands.

## What Changes
- **Federated case share**: share a case with a remote organisation (NC cloud id, `slug@host`) via OpenRegister's `FederationShareService`. Procest builds a purpose-made, field-scoped `caseFederatedShare` snapshot object (never the live case) and shares *that* object (`scope: object`, `permissions: read`) — so OR's generic object-federation endpoint only ever serves what procest explicitly redacted in. A hard-coded allow-list of case-summary fields is enforced server-side; requesting a field outside the allow-list is rejected, not silently dropped.
- **Shared collaboration surface**: an async, append-only activity/notes stream (`caseFederatedActivity`) scoped to one federated case share, postable from both the owning org (local session) and the remote org (the OR-minted scoped bearer token). True real-time co-editing is explicitly out of scope — documented as a follow-up in design.md, not faked.
- **Zaakoverdracht over federation**: `CaseTransferService::initiateTransfer/acceptTransfer/rejectTransfer` gain an optional `remoteCloudId`. A federated transfer mints its own scoped OR share (one per transfer object) used only to authenticate the remote accept/reject call; procest's own state machine (not OR's generic object PUT) validates the transition, so a remote actor can change the transfer's status and nothing else. Idempotent accept/reject, custody audit trail on the transfer object itself, ambiguous/non-pending states refused loudly.
- **Security**: fail-closed when the OR federation leaf is unavailable (unlike the existing basic-share paths, which fail-open because there's nothing to protect there — a cross-org boundary is different). Revocation flips the single OR `FederatedShare.status` row that every read/activity/transfer check consults, so it takes effect immediately across all surfaces. Every cross-org action is audited via `TenantAuditTrailService`.
- **Fixes a pre-existing orphaned-capability gap**: `ShareTab.vue`, `CreateShareDialog.vue` and `CaseTransferDialog.vue` existed but were never mounted anywhere (zero imports outside their own files) — the existing partner-share/transfer UI was dead code with live routes behind it. This change wires them (plus the new federated-share/activity/transfer UI) into the case detail sidebar as a "Sharing" tab, so neither the pre-existing nor the new capability ships orphaned.
- **Docs**: `docs/Features/case-sharing-collaboration.md` "Planned Features" list is corrected to reflect what is actually shipped vs. still open.

## Impact
- Affected specs: NEW capability `federated-case-collaboration`.
- Affected code:
  - `lib/Settings/procest_register.json` (new schemas `caseFederatedShare`, `caseFederatedActivity`; extended `casetransfer`)
  - `lib/Service/SettingsService.php` (schema config wiring)
  - `lib/Service/CaseSharingService.php` (federated share create/revoke)
  - `lib/Service/CaseTransferService.php` (federated transfer + pre-existing gaps)
  - `lib/Service/CaseCollaborationService.php` (NEW)
  - `lib/Controller/CaseSharingController.php` (new + hardened endpoints)
  - `appinfo/routes.php` (new routes)
  - `src/views/cases/components/{ShareTab,CreateShareDialog,CaseTransferDialog}.vue` (wired + extended)
  - `src/views/cases/components/{CaseSharingTab,CreateFederatedShareDialog,FederatedActivityPanel}.vue` (NEW)
  - `src/views/public/PublicFederatedTransferPage.vue` (NEW)
  - `src/registry.js`, `src/customComponents.js`, `src/manifest.json`
  - `docs/Features/case-sharing-collaboration.md`
- No changes to OpenRegister — its federation primitive is sufficient as-is (decision recorded in design.md).
- No new composer/npm dependencies.
