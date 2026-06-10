# Tasks — Member 03: Multi-User RBAC (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

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
