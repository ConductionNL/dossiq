# Tasks — Member 03: Multi-User RBAC (code)

Traces to giant task 1.2 and the invite portion of 4.5; spec REQ-002.

- [~] Implement `SupplierUserManagementService.inviteSupplierUser(supplierRef, email, role)` — status=invited, 64-char token, send email — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement activation endpoint GET /users/activate?token={token} — validate token, link eHerkenning user, KvK match check, set active — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement role update endpoint POST /users/{userId}/role — admin-only, validate, log change — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement revoke endpoint DELETE /users/{userId} — set status=revoked, invalidate sessions — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `DashboardTabs` component — show/hide tabs per role→tab matrix (finance Profile limited to address/contact) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `roleGuard` middleware — protect routes by role — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build Team management page — list members, edit roles, revoke, invite (admin only) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Send member email notifications on role change and on revocation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Write audit entries for invite, role change, and revoke — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test all role + tab combinations (4 roles x 7 tabs) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test invitation email delivery and 7-day token expiry — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test session invalidation after role revocation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test activation rejection on KvK mismatch — deferred to downstream cycle / fleet-wide adoption (handoff)
