# Design — Member 03: Multi-User RBAC (code)

## Scope

Implement the supplier-admin user lifecycle (invite/activate/role/revoke) and role-based tab
visibility, reading/writing the `SupplierUser` schema from member 01.

## Declarative-first (ADR-031) note

No new schema. The role→tab visibility matrix is data-driven from the `SupplierUser.role` field;
the guard is code. Records via OpenRegister ObjectService (ADR-001).

## Approach

- `inviteSupplierUser(supplierRef, email, role)` creates a `SupplierUser` with `status` = invited,
  a 64-char activation token (7-day expiry), and sends an invitation email.
- Activation endpoint validates the token, redirects through eHerkenning, and only activates when
  the authenticated KvK claim matches the invited Supplier.
- Role update + revoke endpoints require the caller's `role` = admin, write an audit entry, and
  notify the affected member. Revoke invalidates all of that user's sessions.
- `roleGuard` middleware protects routes by role; `DashboardTabs` hides unauthorized tabs per the
  REQ-002-C matrix (finance Profile is address/contact only).

## Security (ADR-005)

- Admin-only mutations: role change, revoke, invite (re-auth required per REQ-009-B).
- Activation token single-use, expiring, KvK-bound — prevents account takeover.
- Revocation is effective on next session refresh (no orphaned access).
- All role/access changes audit-logged.
