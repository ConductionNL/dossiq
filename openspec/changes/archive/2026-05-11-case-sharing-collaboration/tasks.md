# Tasks: case-sharing-collaboration

## Implementation Tasks

### Schema & Configuration

- [x] **T01**: Add `caseShare`, `partnerOrganization`, `sharePermissionLevel`, `caseTransfer` schemas to `procest_register.json` with all fields from design doc. Add corresponding config keys to `SettingsService.php` (CONFIG_KEYS and SLUG_TO_CONFIG_KEY arrays).

### Backend Services

- [x] **T02**: Create `lib/Service/CaseSharingService.php` — Service with methods: `createTokenShare(caseId, permissionLevel, expiresAt, password, label, fieldExclusions)` generates 128-bit token via `bin2hex(random_bytes(16))`, stores share in OpenRegister; `createPartnerShare(caseId, partnerId, permissionLevel)` creates partner-scoped share; `getSharesByCase(caseId)` returns all active shares; `revokeShare(shareId, userId)` sets revokedAt/revokedBy; `modifyShare(shareId, data)` updates permission level or expiration; `validateToken(token, password)` checks token validity, expiration, password, and lockout; `getSharedCaseData(share)` returns case data filtered by permission level and field exclusions; `maskBsn(bsn)` returns masked BSN (last 4 digits). Uses ObjectService from OpenRegister.

- [x] **T03**: Create `lib/Service/CaseTransferService.php` — Service with methods: `initiateTransfer(caseId, targetPartnerId, reason, requestedDate)` creates transfer request; `acceptTransfer(transferId)` copies case to target register; `rejectTransfer(transferId, reason)` updates status to rejected. Sends notifications via NotificatieService.

### Controllers

- [x] **T04**: Create `lib/Controller/CaseSharingController.php` — Authenticated controller with endpoints: `listShares(caseId)`, `createShare()`, `modifyShare(shareId)`, `revokeShare(shareId)`, `listPartners()`, `createPartner()`, `updatePartner(partnerId)`, `initiateTransfer()`, `handleTransfer(transferId)`. All methods use `@NoAdminRequired` annotation. Returns JSONResponse.

- [x] **T05**: Create `lib/Controller/PublicShareController.php` — Public controller (no auth) with endpoints: `accessShare(token)` validates token and returns filtered case data, `addComment(token)` adds external comment, `uploadDocument(token)` handles file upload for contribute-level shares, `viewStatus(token)` returns citizen-facing status data. Uses `@PublicPage` and `@NoCSRFRequired` annotations.

### Routes

- [x] **T06**: Add routes to `appinfo/routes.php` — Share management routes under `/api/shares/`, partner routes under `/api/partners/`, transfer routes under `/api/transfers/`, public routes under `/api/public/share/` and `/api/public/status/`. All before the SPA catch-all route.

### Background Job

- [x] **T07**: Create `lib/BackgroundJob/ShareMaintenanceJob.php` — TimedJob (daily) that checks for shares expiring within 3 days and sends notifications to case workers via NotificatieService.

### Frontend Components

- [x] **T08**: Create `src/views/cases/components/ShareTab.vue` — Sidebar tab component listing active shares with type badge (link/partner), recipient, permission level, dates, and action buttons (revoke/modify). Includes "Deel link maken" and "Deel met partner" buttons that open CreateShareDialog.

- [x] **T09**: Create `src/views/cases/components/CreateShareDialog.vue` — Dialog with tabs for token share (expiration date picker, permission level select, optional password) and partner share (partner selector, permission level). On submit, calls sharing API and shows generated link for token shares.

- [x] **T10**: Create `src/views/cases/components/CaseTransferDialog.vue` — Dialog for initiating case transfer: partner selector, reason textarea, requested date picker. Shows pending transfers with accept/reject buttons for incoming transfers.

- [x] **T11**: Create `src/views/settings/PartnerAdmin.vue` — Partner organization management page with list view and create/edit form (name, OIN, contact email, default permission level).

- [x] **T12**: Create `src/views/public/PublicCaseView.vue` — Standalone public page for token-based case access. Shows case title, status, milestone progress, and allowed actions based on permission level. Dutch language UI. Error states for expired/revoked tokens.

- [x] **T13**: Create `src/views/public/PublicStatusPage.vue` — Citizen case progress page with visual step indicator, status label, expected completion date. WCAG AA compliant, NL Design System tokens. No authentication required.

### Integration

- [x] **T14**: Update `src/views/cases/CaseDetail.vue` — Add ShareTab to sidebar tabs in sidebarProps. Add "Overdragen" button to header actions that opens CaseTransferDialog.

## Verification Tasks

- [ ] **V01**: All new files created and syntactically valid
- [ ] **V02**: Schema definitions in procest_register.json are valid JSON with correct field types
- [ ] **V03**: Routes registered correctly before SPA catch-all
- [ ] **V04**: Token generation uses cryptographically secure random bytes
- [ ] **V05**: Field-level filtering enforced at service layer
- [ ] **V06**: Public endpoints accessible without authentication
- [ ] **V07**: BSN masking shows only last 4 digits
- [ ] **V08**: Share revocation immediately blocks access
- [ ] **V09**: Password lockout after 5 failed attempts for 15 minutes
- [ ] **V10**: All Dutch language strings used in public-facing pages
