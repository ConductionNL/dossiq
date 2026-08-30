# supplier-portal Specification — Member 03: Multi-User RBAC

---
status: proposed
---

## Purpose

Allow supplier admins to invite team members, assign roles, revoke access, and enforce role-based
dashboard tab visibility. Consumes the `SupplierUser` schema from member 01 and the session from
member 02.

## ADDED Requirements

### Requirement: Supplier Admin User Invitation and Activation

The system SHALL let a supplier admin invite a colleague by email and role, and SHALL activate the
invitation only when the invitee authenticates via eHerkenning with a matching KvK claim.

#### Scenario: Admin invites a colleague

- GIVEN a SupplierUser with `role` = admin
- WHEN they submit an invitation with an email and a role
- THEN a `SupplierUser` SHALL be created with `status` = invited and a 64-char activation token
  that expires in 7 days
- AND an invitation email containing the activation link SHALL be sent

#### Scenario: Activation rejects a mismatched organisation

- GIVEN a valid, unexpired activation token
- WHEN the invitee authenticates via eHerkenning
- THEN if the authenticated KvK claim matches the invited Supplier the SupplierUser `status` SHALL
  become active and the token marked used
- AND if the KvK claim does not match, activation SHALL be rejected with an error and no access
  granted

### Requirement: Role Management and Tab Visibility

The system SHALL restrict role changes and access revocation to admins, log every change, and
render dashboard tabs according to the role→tab matrix.

#### Scenario: Admin changes a member role

- GIVEN a SupplierUser with `role` = admin viewing a team member
- WHEN they change the member's role and confirm
- THEN the member's `role` SHALL be updated, an audit entry written, and the member emailed
- AND a non-admin caller SHALL be denied the role-change endpoint

#### Scenario: Revocation invalidates access and tabs follow role

- GIVEN an admin revokes a member's access
- WHEN the member's sessions next refresh
- THEN the member SHALL be logged out and unable to re-access
- AND a finance-role user SHALL see the Invoices tab but NOT the Tenders tab, per the role matrix
