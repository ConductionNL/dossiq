# Proposal: case-sharing-collaboration

## Summary

Implement case sharing and collaboration infrastructure for Procest, enabling inter-organizational case access via secure token links and registered partner organizations (ketenpartners). Includes granular permission levels with field-level control, share lifecycle management, external activity tracking, case transfer between organizations, public citizen status pages, and data minimization for shared access.

## Motivation

Dutch government case processing requires collaboration between organizations (housing corporations, police, healthcare providers). Currently this happens via email with document attachments, losing audit trail and version control. This spec enables structured case sharing with access controls, partner organization management, and public case status pages for citizens.

## Affected Projects

- [ ] Project: `procest` — Backend services, controllers, schemas, and Vue components for case sharing

## Scope

### In Scope

- **Token-based sharing** — Generate secure share links with configurable permissions (view/comment/contribute), expiration, and optional password protection
- **Partner organization management** — Register ketenpartners, manage partner users, partner portal with "Gedeelde zaken" view
- **Granular permissions** — Three default permission levels with field-level exclusion configuration
- **Share lifecycle** — View, modify, revoke active shares; bulk share management per partner
- **External activity tracking** — Audit trail for all external party actions on shared cases
- **Case transfer** — Transfer case ownership between organizations with accept/reject flow
- **Public status page** — Citizen-facing case progress page (token-based, no auth required)
- **Share notifications** — Nextcloud notifications for share events, email notifications via n8n
- **Data minimization** — BSN masking, metadata stripping, configurable field exclusions per AVG/GDPR

### Out of Scope

- Real-time collaborative editing
- Federated identity management between municipalities
- Automated case routing based on jurisdiction
- eHerkenning authentication integration (future spec)

## Approach

1. Add new schemas to `procest_register.json`: `caseShare`, `partnerOrganization`, `sharePermissionLevel`, `caseTransfer`
2. Create `CaseSharingService` for share CRUD, token generation, permission enforcement, and field filtering
3. Create `CaseSharingController` for share API endpoints
4. Create `PublicShareController` for unauthenticated token-based access
5. Add Vue components: share dialog, partner management, public status page
6. Register Nextcloud background job for share maintenance (expiration reminders)
7. Update `SettingsService` with new schema config keys

## Risks

- Token security: must use cryptographically secure random tokens with sufficient entropy
- Field-level filtering must be enforced at API layer, not just UI
- Public endpoints need rate limiting to prevent enumeration attacks
