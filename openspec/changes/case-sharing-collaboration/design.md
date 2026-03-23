# Design: case-sharing-collaboration

## Architecture Overview

Case sharing adds a collaboration layer on top of the existing case management infrastructure. Share records link cases to external parties (via tokens or partner accounts) with scoped permissions. A dedicated public controller serves unauthenticated access for token-based shares and citizen status pages.

```
CaseDetail.vue
├── ShareTab (new sidebar tab)
│   ├── ShareList.vue (active shares with revoke/modify)
│   ├── CreateShareDialog.vue (token or partner share)
│   └── PartnerSelector.vue (registered ketenpartners)
├── CaseTransferDialog.vue (transfer ownership)
└── ActivityTimeline.vue (existing, extended with share events)

Settings
├── PartnerAdmin.vue (manage partner organizations)
└── SharePermissionConfig.vue (configure permission levels)

Public (unauthenticated)
├── PublicCaseView.vue (shared case view for external parties)
└── PublicStatusPage.vue (citizen case progress)
```

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/CaseSharingService.php` | Share CRUD, token generation (128-bit), permission enforcement, field filtering, data minimization |
| `lib/Controller/CaseSharingController.php` | Authenticated API for share management (create, list, revoke, modify shares) |
| `lib/Controller/PublicShareController.php` | Unauthenticated endpoints for token-based case access and citizen status pages |
| `lib/BackgroundJob/ShareMaintenanceJob.php` | Daily job: expiration reminders, cleanup expired shares |

### New Frontend Files

| File | Purpose |
|------|---------|
| `src/views/cases/components/ShareTab.vue` | Sidebar tab showing active shares with management controls |
| `src/views/cases/components/CreateShareDialog.vue` | Dialog for creating token or partner shares with permission config |
| `src/views/cases/components/CaseTransferDialog.vue` | Dialog for initiating/accepting/rejecting case transfers |
| `src/views/settings/PartnerAdmin.vue` | Partner organization management (CRUD) |
| `src/views/public/PublicCaseView.vue` | Public case view for external parties (token-based) |
| `src/views/public/PublicStatusPage.vue` | Citizen case progress page (milestone indicator) |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `caseShare`, `partnerOrganization`, `sharePermissionLevel`, `caseTransfer` schemas |
| `lib/Service/SettingsService.php` | Add config keys for new schemas |
| `appinfo/routes.php` | Add share management and public access routes |
| `src/views/cases/CaseDetail.vue` | Add ShareTab to sidebar tabs |

## Data Model

### caseShare Schema
- `token` (string, 32-char hex, indexed) — Secure access token
- `caseId` (string, UUID) — Reference to shared case
- `shareType` (enum: token/partner) — Type of share
- `partnerId` (string, UUID, nullable) — Reference to partner organization
- `permissionLevel` (string) — Permission level slug
- `expiresAt` (string, ISO 8601, nullable) — Expiration datetime
- `password` (string, nullable) — Bcrypt hashed password
- `failedAttempts` (integer, default 0) — Failed password attempts
- `lockedUntil` (string, ISO 8601, nullable) — Lock expiry after failed attempts
- `label` (string) — Human-readable share label
- `fieldExclusions` (array of strings) — Fields to exclude from shared view
- `createdBy` (string) — User who created the share
- `lastAccessedAt` (string, ISO 8601, nullable) — Last external access
- `revokedAt` (string, ISO 8601, nullable) — Revocation timestamp
- `revokedBy` (string, nullable) — User who revoked

### partnerOrganization Schema
- `name` (string) — Organization name
- `slug` (string) — URL-safe identifier
- `oin` (string, nullable) — Organisatie-identificatienummer
- `contactEmail` (string) — Primary contact email
- `defaultPermissionLevel` (string) — Default permission level for new shares
- `groupId` (string) — Nextcloud group ID (ketenpartner_{slug})
- `isActive` (boolean) — Whether partner is active

### caseTransfer Schema
- `caseId` (string, UUID) — Case being transferred
- `sourceOrganization` (string) — Source org identifier
- `targetOrganization` (string, UUID) — Target partner org
- `reason` (string) — Transfer reason
- `requestedDate` (string, ISO 8601) — Requested transfer date
- `status` (enum: pending/accepted/rejected) — Transfer status
- `rejectionReason` (string, nullable) — Reason for rejection
- `completedAt` (string, ISO 8601, nullable) — When transfer completed

## API Design

### Authenticated Endpoints (CaseSharingController)
- `GET /api/shares/{caseId}` — List shares for a case
- `POST /api/shares` — Create a share (token or partner)
- `PUT /api/shares/{shareId}` — Modify share permissions
- `DELETE /api/shares/{shareId}` — Revoke a share
- `GET /api/partners` — List partner organizations
- `POST /api/partners` — Register new partner
- `PUT /api/partners/{partnerId}` — Update partner
- `POST /api/transfers` — Initiate case transfer
- `PUT /api/transfers/{transferId}` — Accept/reject transfer

### Public Endpoints (PublicShareController)
- `GET /api/public/share/{token}` — Access shared case via token
- `POST /api/public/share/{token}/comment` — Add comment on shared case
- `POST /api/public/share/{token}/upload` — Upload document on shared case
- `GET /api/public/status/{token}` — Citizen case status page

## Security Considerations

- Tokens: 128-bit entropy (32 hex chars) via `random_bytes(16)`
- Password hashing: bcrypt via `password_hash()`
- Rate limiting: 5 failed password attempts = 15-minute lock
- Field filtering: enforced at service layer before serialization
- BSN masking: show only last 4 digits in shared views
- Public endpoints: no Nextcloud auth required, CSRF exempt
- Cross-tenant: 404 (not 403) for unauthorized access attempts
