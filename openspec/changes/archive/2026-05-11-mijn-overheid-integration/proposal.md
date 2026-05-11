# Proposal: Mijn Overheid Integration

## Summary

Send official government messages to the national Mijn Overheid Berichtenbox from within Procest case context. Messages follow strict format requirements and support read tracking.

## Problem

Dutch municipalities must send official correspondence (beschikkingen, status updates) through Mijn Overheid Berichtenbox rather than postal mail. Currently there is no integration -- case workers would need to use a separate system to send messages, losing the audit trail connection.

## Scope -- MVP

**In scope:**
- Send plain text messages to Berichtenbox by BSN
- Send messages with single PDF attachment (max 10 MB)
- Bericht type codes per zaaktype with configurable defaults
- Read status tracking via polling background job
- Message stored as case document for audit trail
- Admin configuration for API credentials (endpoint, OIN, certificate)
- Validation: BSN required, subject required, body required
- Message composer UI in case detail

**Out of scope:**
- SMS notifications (Berichtenbox only)
- Bulk message sending
- eHerkenning (organization-to-organization) messages
- Real SOAP/REST API integration (MVP uses adapter pattern for future implementation)

## Dependencies

- BSN field on case or linked person record
- Docudesk for PDF generation
- OpenConnector for Berichtenbox API adapter
- Nextcloud background jobs for read status polling
