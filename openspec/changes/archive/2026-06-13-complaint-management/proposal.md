# Complaint Management Implementation

## Why
Dutch municipalities are legally required to handle citizen complaints under Awb chapter 9, with mandated acknowledgment (5 working days), resolution (6 weeks plus an optional 4-week verdaging), the right to be heard (hoorgesprek), and a formal written disposition (oordeel). Currently Procest has no dedicated complaint infrastructure — complaints get logged as generic cases, losing channel-specific intake, deadline math, frequency analysis, and disposition tracking. This makes Awb compliance verifiable only by manual sampling and prevents detection of systemic complaint patterns (e.g., recurring complaints about a single department or employee).

## What Changes
1. New OpenRegister schemas: `complaint`, `hearing`, `complaintDisposition`, `complaintCategory`.
2. `ComplaintList.vue`, `ComplaintDetail.vue`, and `ComplaintDashboardWidget.vue` Vue components for handler workflow.
3. Awb deadline calculation helper (working-day math, verdaging, escalation) reusing `DeadlinePanel.vue`.
4. Intake flow for balie, telefoon, email (n8n), brief, website, socialmedia channels.
5. Bidirectional escalation link between complaints and zaken.
6. Frequency-analysis dashboard with category, department, and employee-threshold alerts.
7. Configurable complaint categories per tenant with default-handler routing.
8. Communication trail (acknowledgment letter via Docudesk, phone-call records, attachment matching by complaint number).

## Impact
- New schemas, new module, new controllers/services, three new Vue components, dashboard extensions.
- Reuses existing case infrastructure (status types, roles, document attachments, activity timeline) for the lifecycle.
- Integrates with n8n (intake + deadline monitoring), Docudesk (letter generation), Nextcloud Calendar (hearings), and Talk (video hearings).

## Out of Scope
- Bezwaarschriften (formal objections — separate workflow).
- Ombudsman case management and external oversight reporting.
- AI/NLP-based automatic classification.
- Citizen-facing complaint submission portal (handled separately).
