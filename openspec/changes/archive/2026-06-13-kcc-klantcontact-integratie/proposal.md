# KCC Klantcontact Integratie Implementation

## Why
Dutch municipalities manage citizen contact through their KlantContactCentrum (KCC), the first point of contact for phone, email, web, chat, and social media inquiries. Today's KCC staff juggle disconnected systems (telephony, Outlook, ticketing, case management, BRP lookup, handwritten notes), resulting in 4.2 minutes average per call (vs. 2.8-minute industry benchmark), 23% of calls needing callback, and fragmented contact history. The Routing Engine and omnichannel integration in Pipelinq's KCC-werkplek need a unified contact data model, intelligent routing by keyword and staff availability, and real-time feedback to agents on case handoff status—reducing resolution time and improving first-contact resolution.

## What Changes
1. New OpenRegister schemas: `contactMoment`, `routingRule`, `kccAgent`, `contactQueue`, `callbackRequest`, `channelVolumeMetric`.
2. CTI integration via OpenConnector to capture inbound phone calls, extract caller ID, and auto-match against BRP/Handelsregister.
3. Keyword-based routing engine with staff workload balancing and skill-matching for automatic case assignment.
4. Omnichannel intake for email (IMAP/Microsoft Graph), web forms (Procest), and chat (MS Teams, WhatsApp via OpenConnector).
5. Call scheduling and callback management with SLA tracking and attempt retry logic.
6. Realtime status feedback to KCC agent when a case is picked up or completed by backoffice.
7. Volume and performance dashboard (contacts per channel, handle time, first-contact resolution, SLA breaches, capacity planning).
8. n8n orchestration for email polling, callback scheduling, SLA monitoring, and notifications.

## Impact
- New schemas, new module, new Vue components for CTI popup, routing admin, and reporting.
- Integrates with OpenConnector (CTI, email, chat), OpenCatalogi (BRP/KvK lookup), Procest (case creation), LaunchPad (dashboard widgets), Docudesk (outbound templates).
- Reuses Procest case infrastructure (status types, activity timeline, document attachments) for contact linking.
- Requires n8n workflows for async email intake, callback scheduling, and deadline monitoring.

## Out of Scope
- Citizen-facing contact submission portal (handled separately in Pipelinq).
- AI/NLP auto-classification of contact subjects (future enhancement).
- Advanced workforce management (scheduling, forecasting beyond trend analysis).
- Integration with legacy on-premise CTI systems (cloud-only for MVP).
- Multi-language speech support in recordings.
