---
status: implemented
---
# case-sharing-collaboration Specification

## Purpose
Share case access with external parties (ketenpartners) for inter-organizational collaboration on cases. Supports both token-based access for ad-hoc sharing and account-based access for recurring partners, with scoped permissions controlling what shared parties can view and do.

## Context
Dutch government case processing frequently requires collaboration between organizations: housing corporations reviewing permit applications, police providing input on event permits, healthcare providers contributing to youth care cases. Currently this happens via email with document attachments, losing audit trail and version control. This spec enables structured case sharing with access controls.

Procest already integrates with Nextcloud's sharing infrastructure (`OCP\Share\IManager`) for file sharing and uses OpenRegister RBAC for permission enforcement. The `ZgwAuthMiddleware` demonstrates external API authentication patterns. This spec extends these foundations to enable case-level sharing with granular permission scoping and partner organization management.

## ADDED Requirements
### Requirement: Share case with external party via secure token link
The system MUST support sharing a case with an external party using a cryptographically secure, time-limited token URL.

#### Scenario: Create share link with configurable permissions
- **GIVEN** a case worker on case "ZAAK-2026-001234"
- **WHEN** they click "Delen" and select "Link delen"
- **THEN** the system MUST generate a unique, cryptographically secure token URL (min 128 bits entropy)
- **AND** the case worker MUST be able to set: expiration date, permission level (bekijken / bekijken + reageren / bekijken + bijdragen), and optional password
- **AND** the share MUST be logged in the case audit trail with: creator, timestamp, permission level, and expiration

#### Scenario: Access shared case via token with view permission
- **GIVEN** a valid share token for case "ZAAK-2026-001234" with "bekijken" permission
- **WHEN** the external party opens the token URL in a browser
- **THEN** they MUST see a public case view with: case title, current status, milestone progress, and selected documents
- **AND** they MUST NOT see internal notes, assigned case worker names, risk scores, or other restricted fields
- **AND** they MUST NOT see any other cases or system navigation

#### Scenario: Access shared case via token with comment permission
- **GIVEN** a valid share token with "bekijken + reageren" permission
- **WHEN** the external party accesses the case
- **THEN** they MUST be able to view case details and add comments
- BUT they MUST NOT be able to upload documents, change case status, or modify any case data
- **AND** comments MUST be tagged with an external party identifier (name or organization, entered on first access)

#### Scenario: Expired token shows Dutch-language error
- **GIVEN** a share token that has passed its expiration date
- **WHEN** the external party attempts to access the case
- **THEN** the system MUST display "Deze link is verlopen. Neem contact op met de behandelaar." and deny access
- **AND** the expired access attempt MUST be logged

#### Scenario: Password-protected share link
- **GIVEN** a share token with password protection enabled
- **WHEN** the external party opens the token URL
- **THEN** a password prompt MUST be displayed before granting access
- **AND** after 5 failed password attempts, the token MUST be temporarily locked for 15 minutes

### Requirement: Share case with registered partner organization
The system MUST support sharing cases with registered partner organizations (ketenpartners) who have persistent accounts.

#### Scenario: Share with registered ketenpartner
- **GIVEN** a registered ketenpartner "Woningbouwvereniging Utrecht" with a partner account in the system
- **WHEN** the case worker shares case "ZAAK-2026-001234" with this partner
- **THEN** the partner's authorized users MUST see the case in their "Gedeelde zaken" view
- **AND** the share MUST be scoped to the configured permission level
- **AND** a notification MUST be sent to the partner organization's primary contact

#### Scenario: Partner organization user management
- **GIVEN** a registered ketenpartner "Woningbouwvereniging Utrecht"
- **WHEN** the partner admin manages their organization's users in the partner portal
- **THEN** they MUST be able to add/remove users who can access shared cases
- **AND** each user MUST authenticate via their own credentials (Nextcloud account, eHerkenning, or local account)
- **AND** user changes MUST take effect immediately (no pending approval)

#### Scenario: Partner sees only shared cases
- **GIVEN** "Woningbouwvereniging Utrecht" has been shared 3 cases from municipality A
- **WHEN** a partner user logs in
- **THEN** they MUST see exactly those 3 cases in their "Gedeelde zaken" view
- **AND** they MUST NOT see any other cases, navigation items, or system configuration
- **AND** the view MUST show: case title, status, shared date, permission level, and municipality name

#### Scenario: Register new partner organization
- **GIVEN** a municipality admin wants to add a new ketenpartner
- **WHEN** they navigate to Settings > Partners and click "Partner toevoegen"
- **THEN** they MUST provide: organization name, OIN (if applicable), contact email, and default permission level
- **AND** the system MUST create an OpenRegister object with the partner organization data
- **AND** a partner admin account MUST be provisioned with a Nextcloud user in the `ketenpartner_{slug}` group

### Requirement: Granular permission levels with field-level control
Shared access MUST be controllable with granular permission levels and field-level restrictions.

#### Scenario: View-only sharing excludes internal fields
- **GIVEN** a case shared with permission level "bekijken"
- **WHEN** the external party views the case
- **THEN** they MUST see: case title, identifier, current status, milestone progress, and public documents
- **AND** they MUST NOT see: internal notes (`interneAantekening`), risk scores (`risicoScore`), assigned case worker, cost estimates, or case history details

#### Scenario: View + contribute sharing allows document upload
- **GIVEN** a case shared with permission level "bekijken + bijdragen"
- **WHEN** the external party accesses the case
- **THEN** they MUST be able to upload documents (max 50 MB per file, PDF/DOC/DOCX/JPG/PNG) and add comments
- **AND** uploaded documents MUST be tagged as "extern aangeleverd" with the uploader's identity
- **AND** they MUST NOT be able to change case status, zaaktype, assigned worker, or delete existing documents

#### Scenario: Field-level share restrictions via configuration
- **GIVEN** a share configuration that includes field exclusions: `["interneAantekening", "risicoScore", "kosteninschatting"]`
- **WHEN** the external party views the case via API or UI
- **THEN** the excluded fields MUST NOT appear in the case view or API response (not even as empty/null)
- **AND** the field exclusion MUST be enforced at the API layer before serialization

#### Scenario: Permission level definitions are configurable per tenant
- **GIVEN** a municipality admin accesses Settings > Deelrechten
- **WHEN** they define permission levels
- **THEN** they MUST be able to create custom permission levels with specific field inclusions/exclusions
- **AND** default permission levels ("bekijken", "bekijken + reageren", "bekijken + bijdragen") MUST be pre-configured

### Requirement: Share lifecycle management
Case workers MUST be able to view, modify, and revoke active shares on their cases.

#### Scenario: View active shares on case detail
- **GIVEN** a case with 3 active shares (2 token-based, 1 partner account)
- **WHEN** the case worker opens the "Delen" tab in the case detail sidebar
- **THEN** all active shares MUST be listed with: type (link/partner), recipient/label, permission level, creation date, expiration date, last accessed date
- **AND** each share MUST have an "Intrekken" (revoke) button and an "Aanpassen" (modify) button

#### Scenario: Revoke share immediately blocks access
- **GIVEN** an active share on a case
- **WHEN** the case worker clicks "Intrekken" and confirms
- **THEN** the external party MUST immediately lose access (next page load shows "Toegang ingetrokken")
- **AND** the revocation MUST be logged in the audit trail with: revoker, timestamp, and share details
- **AND** any active sessions using the revoked share MUST be invalidated

#### Scenario: Modify share permission level
- **GIVEN** a token-based share with "bekijken + bijdragen" permission
- **WHEN** the case worker changes the permission to "bekijken" only
- **THEN** the external party's next access MUST reflect the reduced permissions
- **AND** any pending uploads from the external party MUST still be processed (no data loss)
- **AND** the permission change MUST be logged in the audit trail

#### Scenario: Bulk share management
- **GIVEN** a case worker handles 20 cases shared with "Politie Utrecht"
- **WHEN** the case worker navigates to the partner management view
- **THEN** they MUST see all cases shared with "Politie Utrecht" in a single list
- **AND** they MUST be able to revoke all shares for that partner at once or modify permissions in bulk

### Requirement: External access activity tracking
All actions by external parties on shared cases MUST be tracked in the case audit trail.

#### Scenario: External party views case
- **GIVEN** a shared case accessed by an external party
- **WHEN** they view the case
- **THEN** the audit trail MUST record: "Zaak bekeken door extern: Woningbouwvereniging Utrecht (J. de Vries)" with timestamp and IP address
- **AND** the access MUST be recorded even if the party only views and takes no action

#### Scenario: External party uploads document
- **GIVEN** a case shared with "bekijken + bijdragen" permission
- **WHEN** the external party uploads a document "brandveiligheidsadvies.pdf"
- **THEN** the document MUST be stored in the case's Nextcloud folder under a subfolder "Extern aangeleverd"
- **AND** the document MUST be tagged with: uploader identity, upload timestamp, and source organization
- **AND** the audit trail MUST record: "Document geupload door extern: Woningbouwvereniging Utrecht - brandveiligheidsadvies.pdf"

#### Scenario: External party adds comment
- **GIVEN** a case shared with "bekijken + reageren" permission
- **WHEN** the external party adds a comment "Brandveiligheid voldoet aan eisen"
- **THEN** the comment MUST be stored with: author (external party identity), timestamp, and "extern" tag
- **AND** the comment MUST be visible to case workers in the activity timeline
- **AND** the case worker MUST receive a notification about the new external comment

### Requirement: Case transfer between organizations
The system MUST support transferring case ownership from one organization to another.

#### Scenario: Initiate case transfer
- **GIVEN** case "ZAAK-2026-001234" is owned by municipality A
- **AND** municipality B's organization is registered as a ketenpartner
- **WHEN** the case worker initiates a transfer to municipality B
- **THEN** the system MUST create a transfer request with: source org, target org, case reference, reason, and requested transfer date
- **AND** the target organization's admin MUST receive a notification to accept or reject the transfer

#### Scenario: Accept case transfer
- **GIVEN** a pending transfer request for case "ZAAK-2026-001234"
- **WHEN** the target organization's admin accepts the transfer
- **THEN** the case MUST be copied to the target organization's register
- **AND** all documents, status history, and milestone records MUST be included
- **AND** the source organization MUST retain a read-only archive copy
- **AND** both organizations' audit trails MUST record the transfer

#### Scenario: Reject case transfer with reason
- **GIVEN** a pending transfer request
- **WHEN** the target organization's admin rejects the transfer with reason "Niet bevoegd"
- **THEN** the source case worker MUST be notified with the rejection reason
- **AND** the case MUST remain with the source organization unchanged

### Requirement: Public case status page for citizens
Citizens MUST be able to check their case progress via a public URL without authentication.

#### Scenario: Citizen receives case status link
- **GIVEN** a citizen submitted a permit application creating case "ZAAK-2026-001234"
- **WHEN** the case worker sends a status notification
- **THEN** the notification MUST include a public status URL (e.g., `/publiek/zaak/{token}`)
- **AND** the token MUST be unique, non-guessable, and linked to the specific case

#### Scenario: Citizen views case progress
- **GIVEN** a citizen opens the public status URL
- **THEN** they MUST see: case title, current milestone progress (visual step indicator), current status label, and expected completion date
- **AND** they MUST NOT see: case worker details, internal notes, documents, or any actionable controls
- **AND** the page MUST comply with WCAG 2.1 AA and use NL Design System tokens

#### Scenario: Public status page respects case sensitivity
- **GIVEN** a case is marked as "vertrouwelijk" (confidential)
- **WHEN** the system generates a status notification
- **THEN** the public status URL MUST NOT be generated
- **AND** the citizen MUST be informed via alternative channels (letter, phone)

### Requirement: Notification system for share events
Case workers and external parties MUST be notified about share-related events.

#### Scenario: Case worker notified of external activity
- **GIVEN** a case shared with a ketenpartner
- **WHEN** the ketenpartner uploads a document or adds a comment
- **THEN** the case worker MUST receive a Nextcloud notification: "Extern document ontvangen op ZAAK-2026-001234 van Woningbouwvereniging Utrecht"
- **AND** the notification MUST link to the case detail view

#### Scenario: External party notified of case updates
- **GIVEN** a case shared with a ketenpartner with "bekijken" permission
- **WHEN** the case status changes
- **THEN** the ketenpartner's primary contact MUST receive an email notification: "Status update voor ZAAK-2026-001234: Besluit genomen"
- **AND** the email MUST include a link to the shared case view (not the internal case detail)

#### Scenario: Share expiration reminder
- **GIVEN** a token-based share expiring in 3 days
- **WHEN** the daily share maintenance job runs
- **THEN** the case worker MUST receive a notification: "Deellink voor ZAAK-2026-001234 verloopt over 3 dagen"
- **AND** they MUST be able to extend the expiration directly from the notification

### Requirement: Data minimization for shared access
Shared case views MUST apply data minimization principles per AVG/GDPR.

#### Scenario: Personal data excluded from partner view
- **GIVEN** a case about a building permit that includes the applicant's BSN, address, and phone number
- **WHEN** shared with a ketenpartner for technical review
- **THEN** the applicant's BSN MUST be masked (showing only last 4 digits)
- **AND** personal contact details MUST be excluded unless the permission level explicitly includes them
- **AND** the data minimization rules MUST be configurable per permission level

#### Scenario: Document metadata stripped for external access
- **GIVEN** a case document containing metadata (author, revision history, comments)
- **WHEN** an external party downloads the document via a shared case view
- **THEN** internal metadata MUST be stripped from the downloaded copy
- **AND** the original document in Nextcloud MUST remain unchanged

#### Scenario: Audit report for shared personal data
- **GIVEN** a case with personal data was shared with 3 ketenpartners over 6 months
- **WHEN** a privacy officer requests a data sharing report
- **THEN** the system MUST generate: which personal data fields were shared, with whom, when, for how long, and under which legal basis

## Non-Requirements
- This spec does NOT cover real-time collaborative editing (simultaneous case editing by multiple parties)
- This spec does NOT cover federated identity management between municipalities
- This spec does NOT cover automated case routing between organizations based on jurisdiction

## Dependencies
- OpenRegister RBAC for permission enforcement
- Nextcloud share infrastructure (`OCP\Share\IManager`) for token generation and expiration management
- Nextcloud notification system (`OCP\Notification\IManager`) for share event notifications
- Audit trail system (OpenRegister audit trails plugin) for tracking external access
- NL Design System tokens for public case status page styling
- n8n for email notifications to external parties
- Partner organization registry (new OpenRegister schema: `partnerOrganization`)
- CaseDetail.vue sidebar for "Delen" tab integration

---

### Current Implementation Status

**Not yet implemented.** No sharing, token-based access, or ketenpartner collaboration functionality exists in the Procest codebase. There are no share-related schemas, controllers, services, or Vue components.

**Foundation available:**
- Nextcloud's share infrastructure (`OCP\Share\IManager`) provides token-based sharing with expiration, password protection, and permission levels -- could be leveraged for case sharing.
- OpenRegister RBAC provides the permission enforcement layer.
- The audit trail plugin in the object store (`auditTrailsPlugin` in `src/store/modules/object.js`) could track external access events.
- ZGW authentication middleware (`lib/Middleware/ZgwAuthMiddleware.php`) demonstrates external API authentication patterns that could be adapted for partner access.
- The `CaseDetail.vue` sidebar already supports tabs (via `sidebarProps`) where a "Delen" tab could be added.
- The `role` schema in OpenRegister could represent external party roles on shared cases.

**Partial implementations:** None.

### Standards & References

- **Nextcloud Sharing API**: Token-based sharing with expiration, passwords, and permission scopes. Procest can extend Nextcloud's `IShare` interface for case-level sharing.
- **eHerkenning**: Dutch government-to-business authentication standard for partner organization users. Level 3 (substantieel) recommended for case access.
- **DigiD**: Dutch citizen authentication for citizen-facing case access (out of scope for this spec but relevant for public status page).
- **AVG/GDPR**: Data sharing with external parties requires purpose limitation, data minimization, and processing agreements. Article 28 (processor agreements) applies to ketenpartner data access.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Security requirements for government data sharing, including access logging, encryption in transit, and data classification.
- **Common Ground**: Federated data access patterns for inter-organizational collaboration. The "notificeren" and "autoriseren" components are relevant.
- **ZGW Autorisaties API (VNG)**: Authorization scopes for external system access to case data. Could model permission levels as ZGW autorisatie objects.
- **ArkCase**: Uses `AcmParticipant` model for access control on cases -- participants can be internal users, groups, or external contacts. Similar pattern to Procest's ketenpartner concept.
- **Dimpact ZAC**: Shares cases between groups via group-based assignment. Does not support external organization sharing -- an opportunity for Procest differentiation.
- **Ketensamenwerking**: Dutch government term for chain collaboration between public organizations. VNG has published guidelines for secure ketendata exchange.
