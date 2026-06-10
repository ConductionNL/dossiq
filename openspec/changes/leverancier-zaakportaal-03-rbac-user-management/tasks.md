# Tasks — Member 03: Multi-User RBAC (code)

Traces to giant task 1.2 and the invite portion of 4.5; spec REQ-002.

- [ ] Implement `SupplierUserManagementService.inviteSupplierUser(supplierRef, email, role)` — status=invited, 64-char token, send email
- [ ] Implement activation endpoint GET /users/activate?token={token} — validate token, link eHerkenning user, KvK match check, set active
- [ ] Implement role update endpoint POST /users/{userId}/role — admin-only, validate, log change
- [ ] Implement revoke endpoint DELETE /users/{userId} — set status=revoked, invalidate sessions
- [ ] Create `DashboardTabs` component — show/hide tabs per role→tab matrix (finance Profile limited to address/contact)
- [ ] Implement `roleGuard` middleware — protect routes by role
- [ ] Build Team management page — list members, edit roles, revoke, invite (admin only)
- [ ] Send member email notifications on role change and on revocation
- [ ] Write audit entries for invite, role change, and revoke
- [ ] Test all role + tab combinations (4 roles x 7 tabs)
- [ ] Test invitation email delivery and 7-day token expiry
- [ ] Test session invalidation after role revocation
- [ ] Test activation rejection on KvK mismatch
