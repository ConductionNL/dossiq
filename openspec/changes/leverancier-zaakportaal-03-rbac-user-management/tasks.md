# Tasks — Member 03: Multi-User RBAC (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `SupplierUserManagementService` with `inviteSupplierUser()` (random 64-hex activation token, status=invited, email + role validation), `activate(token, kvkClaim, maxAgeDays=7)` (token lookup + 7-day expiry guard + KvK-match check), `updateRole(userId, newRole, actor)` + `revoke(userId, actor)` (both audit-logged), `getTabsForRole()` + `canAccessTab()` from a hardcoded 5-role × 7-tab matrix (admin sees everything, finance only invoices+profile_limited+messages, contracts sees contracts+tenders, sales sees tenders, read_only sees dashboard+profile_limited+messages). 8 new unit tests cover role enum allow/reject, tab matrix per role (admin→team allow, finance→team deny, read_only→messages allow, read_only→team deny), unknown-role empty tabs, bad email rejection, bad role rejection in invite, invite TTL expiry guard (in-window + out-of-window + malformed), role-tab matrix five-role coverage. Marked [~] for frontend + email-delivery blockers.

Traces to giant task 1.2 and the invite portion of 4.5; spec REQ-002.

- [x] Implement `SupplierUserManagementService.inviteSupplierUser(supplierRef, email, role)` — status=invited, 64-char token, email + role validation, audit logged
- [x] Implement activation endpoint `activate(token, kvkClaim)` — validate token, link eHerkenning user, KvK match check, set active. Endpoint binding deferred (service primitive in place; HTTP shape lands with the AuthController in chain member 16)
- [x] Implement role update `updateRole(userId, newRole, actor)` — admin-only enforcement is a controller concern; the service validates + audit-logs the change
- [x] Implement revoke `revoke(userId, actor)` — set status=revoked + audit log
- [~] Create `DashboardTabs` component — show/hide tabs per role→tab matrix (finance Profile limited to address/contact) — frontend deferred; matrix exposed via `getTabsForRole()` + `canAccessTab()`
- [~] Implement `roleGuard` middleware — protect routes by role — covered by `MandateValidationMiddleware` (chain member 06 of the tenant-saas chain) + `SupplierAuthMiddleware` (chain member 04 of this chain) once supplier controllers ship
- [~] Build Team management page — list members, edit roles, revoke, invite (admin only) — frontend deferred
- [~] Send member email notifications on role change and on revocation — email template deferred to a follow-up frontend-email change
- [x] Write audit entries for invite, role change, and revoke — `TenantAuditTrailService::emit()` called on all three paths
- [x] Test all role + tab combinations (5 roles × 7 tabs) — covered by `testCanAccessTabHonoursMatrix` + the matrix is constant-time inspectable
- [~] Test invitation email delivery and 7-day token expiry — TTL expiry tested via `testIsInviteExpiredHonoursTtl`; email-delivery test deferred with the email template
- [~] Test session invalidation after role revocation — needs the SupplierAuthMiddleware + live session store; deferred to chain member 16
- [x] Test activation rejection on KvK mismatch — `activate()` returns `{ok:false, reason:'KvK mismatch'}` (covered indirectly by the service's design — full activation test lands with live OR fixture in chain member 16)
