# case-sharing-collaboration Specification

## Purpose
Share case access with external parties (ketenpartners) for inter-organizational collaboration on cases. Supports both token-based access for ad-hoc sharing and account-based access for recurring partners, with scoped permissions controlling what shared parties can view and do.

## Context
Dutch government case processing frequently requires collaboration between organizations: housing corporations reviewing permit applications, police providing input on event permits, healthcare providers contributing to youth care cases. Currently this happens via email with document attachments, losing audit trail and version control. This spec enables structured case sharing with access controls.

## ADDED Requirements

### Requirement: Share case with external party via token
The system MUST support sharing a case with an external party using a secure token link.

#### Scenario: Create share link
- GIVEN a case worker on case "ZAAK-2026-001234"
- WHEN they click "Delen" and select "Link delen"
- THEN the system MUST generate a unique, cryptographically secure token URL
- AND the case worker MUST be able to set: expiration date, permission level, optional password
- AND the share MUST be logged in the case audit trail

#### Scenario: Access shared case via token
- GIVEN a valid share token for case "ZAAK-2026-001234" with "view + comment" permission
- WHEN the external party opens the token URL
- THEN they MUST see the case details scoped to the permission level
- AND they MUST be able to add comments but NOT modify case data
- AND they MUST NOT see other cases or system data

#### Scenario: Expired token
- GIVEN a share token that has passed its expiration date
- WHEN the external party attempts to access the case
- THEN the system MUST display "Deze link is verlopen" and deny access

### Requirement: Share case with partner organization account
The system MUST support sharing cases with registered partner organizations.

#### Scenario: Share with registered partner
- GIVEN a registered ketenpartner "Woningbouwvereniging Utrecht" with a partner account
- WHEN the case worker shares case "ZAAK-2026-001234" with this partner
- THEN the partner's users MUST see the case in their "Gedeelde zaken" view
- AND the share MUST be scoped to the configured permission level

#### Scenario: Partner organization user management
- GIVEN a registered ketenpartner
- WHEN the partner admin manages their organization's users
- THEN they MUST be able to add/remove users who can access shared cases
- AND each user MUST authenticate via their own credentials (eHerkenning or local account)

### Requirement: Scoped permissions
Shared access MUST be controllable with granular permission levels.

#### Scenario: View-only sharing
- GIVEN a case shared with permission level "bekijken"
- WHEN the external party views the case
- THEN they MUST see case metadata, status, and selected documents
- AND they MUST NOT see internal notes, assigned case worker details, or other restricted fields

#### Scenario: View + contribute sharing
- GIVEN a case shared with permission level "bekijken + bijdragen"
- WHEN the external party accesses the case
- THEN they MUST be able to upload documents and add comments
- AND they MUST NOT be able to change case status, zaaktype, or assigned worker

#### Scenario: Field-level share restrictions
- GIVEN a share configuration that excludes fields "interneAantekening" and "risicoScore"
- WHEN the external party views the case
- THEN the excluded fields MUST NOT appear in the case view or API response

### Requirement: Share management
Case workers MUST be able to manage active shares on their cases.

#### Scenario: View active shares
- GIVEN a case with 3 active shares (2 token-based, 1 partner account)
- WHEN the case worker opens the "Delen" panel
- THEN all active shares MUST be listed with: type, recipient/label, permission level, creation date, expiration
- AND each share MUST have a "Intrekken" (revoke) action

#### Scenario: Revoke share
- GIVEN an active share on a case
- WHEN the case worker revokes the share
- THEN the external party MUST immediately lose access
- AND the revocation MUST be logged in the audit trail

### Requirement: Activity tracking for shared access
All actions by external parties MUST be tracked in the case audit trail.

#### Scenario: External party views case
- GIVEN a shared case accessed by an external party
- WHEN they view the case
- THEN the audit trail MUST record: "Zaak bekeken door extern: Woningbouwvereniging Utrecht (J. de Vries)"

#### Scenario: External party uploads document
- GIVEN a case shared with contribute permission
- WHEN the external party uploads a document
- THEN the document MUST be tagged as "extern aangeleverd"
- AND the audit trail MUST record the upload with the external party's identity

## Dependencies
- OpenRegister RBAC for permission enforcement
- Nextcloud share infrastructure (token generation, expiration management)
- Audit trail system for tracking external access
- Partner organization registry (could be an OpenRegister schema)

### Current Implementation Status

**Not yet implemented.** No sharing, token-based access, or ketenpartner collaboration functionality exists in the Procest codebase. There are no share-related schemas, controllers, services, or Vue components.

**Foundation available:**
- Nextcloud's share infrastructure (`OCP\Share\IManager`) provides token-based sharing with expiration, password protection, and permission levels -- could be leveraged for case sharing.
- OpenRegister RBAC provides the permission enforcement layer.
- The audit trail plugin in the object store (`auditTrailsPlugin` in `src/store/modules/object.js`) could track external access events.
- ZGW authentication middleware (`lib/Middleware/ZgwAuthMiddleware.php`) demonstrates external API authentication patterns.

**Partial implementations:** None.

### Standards & References

- **Nextcloud Sharing API**: Token-based sharing with expiration, passwords, and permission scopes.
- **eHerkenning**: Dutch government-to-business authentication standard for partner organization users.
- **DigiD**: Dutch citizen authentication (for citizen-facing case access).
- **AVG/GDPR**: Data sharing with external parties requires purpose limitation, data minimization, and processing agreements.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Security requirements for government data sharing.
- **Common Ground**: Federated data access patterns for inter-organizational collaboration.
- **ZGW Autorisaties API (VNG)**: Authorization scopes for external system access to case data.
- **Ketensamenwerking**: Dutch government term for chain collaboration between public organizations.

### Specificity Assessment

This spec covers the key sharing scenarios well but lacks technical implementation details.

**What's missing:**
- No specification of how Nextcloud's native share infrastructure is extended or wrapped for case sharing.
- No data model for partner organizations (OpenRegister schema definition).
- No API endpoints for share creation, listing, and revocation.
- No UI wireframes for the "Delen" panel in case detail.
- No specification of field-level access control implementation (how excluded fields are filtered from API responses).
- No specification of the "Gedeelde zaken" view for partner organizations.
- No specification of how partner organization users authenticate (Nextcloud user accounts, LDAP, eHerkenning).

**Open questions:**
1. Should case sharing use Nextcloud's built-in share system or a custom implementation?
2. How is field-level access control enforced -- at the API layer or the database query layer?
3. Can shared access be time-limited per session or only by expiration date?
4. How does sharing interact with the ZGW API layer -- can external systems access shared cases via ZGW endpoints?
