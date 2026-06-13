# KCC Klantcontact Integratie Design

## Architecture
ContactMoment is the core entity, stored in OpenRegister as a persistent record of every customer interaction (inbound or outbound). Each ContactMoment is linked to a Procest case and tagged with channel, direction, outcome, and sentiment. RoutingRule defines decision logic (keyword patterns, channel, time-of-day, customer type) that automatically routes new ContactMoments to the correct domain team. KCCAgent tracks agent availability, skills, and workload; the routing engine balances on workload + skill-match + call history. The system integrates with CTI (via OpenConnector TAPI/REST bridge), email servers (IMAP/Microsoft Graph), and chat platforms (Teams, WhatsApp via OpenConnector). n8n orchestrates async workflows: email polling, callback scheduling, SLA monitoring, and notification fan-out. Status updates flow back to the originating KCC agent via websockets or polling.

## Data Model
Six new OpenRegister schemas in `procest_register.json`:

- **contactMoment** — `id`, `customerRef` (BSN/KvK/external ID), `customerName`, `customerPhone`, `customerEmail`, `channel` (phone/email/web_form/chat/social/in_person/letter), `direction` (inbound/outbound), `startedAt`, `endedAt`, `durationSeconds`, `subject`, `summary`, `transcript`, `outcome` (resolved/transferred/callback_scheduled/escalated), `caseRef`, `kccAgentRef`, `assignedTeam`, `tags[]`, `sentimentScore`.

- **routingRule** — `name`, `priority`, `matchConditions[]` (channel, keywords, regex, customer_type, time_of_day, day_of_week), `assignedDomain` (burgerzaken/openbare_werken/wmo/etc.), `assignedTeam`, `escalationPath`, `enabled`, `createdBy`, `lastModifiedAt`.

- **kccAgent** — `userRef` (Nextcloud user), `availableForChannels[]` (phone, email, chat), `currentStatus` (available/busy/break/after_call_wrap/offline), `skills[]` (language, expertise tags), `currentWorkload` (open contacts count), `dailyContactCount`.

- **contactQueue** — `channel`, `queueName`, `currentDepth`, `averageWaitSeconds`, `slaTargetSeconds`, `slaBreaches`, `staffedAgents[]`.

- **callbackRequest** — `contactMomentRef`, `customerPhone`, `requestedTimeWindow`, `preferredAgent`, `reason`, `scheduledFor`, `status` (scheduled/attempted/completed/failed), `attemptCount`, `nextAttemptAt`.

- **channelVolumeMetric** — `period` (day/week/month), `channel`, `inboundCount`, `outboundCount`, `avgHandleTimeSeconds`, `firstContactResolutionPct`, `customerSatisfactionScore`, `agentOccupancyPct`, `slaBreachCount`.

Seed data (3-5 examples per entity):
```json
contactMoment: [
  {
    "id": "cm-2026-0001",
    "customerRef": "123456789",
    "customerName": "Jan van den Berg",
    "customerPhone": "+31612345678",
    "channel": "phone",
    "direction": "inbound",
    "startedAt": "2026-05-20T09:15:00Z",
    "endedAt": "2026-05-20T09:27:30Z",
    "durationSeconds": 750,
    "subject": "Paspoort verlenging",
    "summary": "Burger vraagt hoe lang paspoort verlenging duurt",
    "outcome": "resolved",
    "caseRef": "2026-0042",
    "kccAgentRef": "maria.santos",
    "assignedTeam": "Burgerzaken",
    "tags": ["paspoort", "verlenging", "urgent"],
    "sentimentScore": 0.7
  }
]

routingRule: [
  {
    "id": "rule-001",
    "name": "Paspoort → Burgerzaken",
    "priority": 1,
    "matchConditions": [
      { "type": "keyword", "value": "paspoort" },
      { "type": "keyword", "value": "id-kaart" }
    ],
    "assignedDomain": "burgerzaken",
    "assignedTeam": "Paspoort Team",
    "enabled": true,
    "createdBy": "admin"
  },
  {
    "id": "rule-002",
    "name": "Openbare Werken → OBR",
    "priority": 2,
    "matchConditions": [
      { "type": "keyword", "value": "lantaarnpaal" },
      { "type": "keyword", "value": "pothole" },
      { "type": "keyword", "value": "straat" }
    ],
    "assignedDomain": "openbare_werken",
    "assignedTeam": "Beheer Openbare Ruimte",
    "enabled": true
  }
]

kccAgent: [
  {
    "id": "agent-001",
    "userRef": "maria.santos",
    "availableForChannels": ["phone", "email", "chat"],
    "currentStatus": "available",
    "skills": ["Nederlands", "Engels", "Burgerzaken"],
    "currentWorkload": 3,
    "dailyContactCount": 42
  }
]

callbackRequest: [
  {
    "id": "cb-2026-0001",
    "contactMomentRef": "cm-2026-0001",
    "customerPhone": "+31612345678",
    "requestedTimeWindow": "2026-05-21 14:00-16:00",
    "scheduledFor": "2026-05-21T14:30:00Z",
    "status": "scheduled",
    "attemptCount": 0
  }
]

channelVolumeMetric: [
  {
    "id": "metric-2026-w20",
    "period": "2026-05-12/2026-05-18",
    "channel": "phone",
    "inboundCount": 1203,
    "outboundCount": 89,
    "avgHandleTimeSeconds": 252,
    "firstContactResolutionPct": 0.68,
    "customerSatisfactionScore": 4.2,
    "agentOccupancyPct": 0.82,
    "slaBreachCount": 12
  }
]
```

## Components
1. **KCCWorkplaceToolbar.vue** — CTI popup showing incoming call (caller ID, BRP match, open cases, contact history); linked to the OpenConnector CTI bridge.
2. **RoutingRuleAdmin.vue** — CRUD interface for routing rules; priority reordering, condition builder (keyword, regex, customer type), team/domain assignment.
3. **CallbackScheduler.vue** — Modal for scheduling callback; time-window picker, preferred-agent selection, retry strategy config.
4. **KCCDashboard.vue** — real-time channel volume (phone/email/chat/web), queue depth, average handle time, first-contact resolution trend, SLA breach alerts, agent occupancy heat map.
5. **AgentStatusPanel.vue** — agent availability toggle, skill tags, workload indicator, current case preview.
6. **ContactDetail.vue** — full contact record with transcript (if recorded), linked case, tags, sentiment score, related contacts, escalation/callback history.

## Backend
- `ContactMomentService` — CRUD, linking to Procest cases, transcript storage, sentiment analysis integration.
- `RoutingEngine` — evaluate rules on new ContactMoment, rank candidates by availability + skill + workload, suggest top-3 agents with motivation ("Anke van der Meer: 5 open cases, WMO-expert, last contact 3 months ago").
- `KCCAgentService` — status transitions (available/busy/break/after_call_wrap), workload tracking, skill catalog.
- `CallbackService` — schedule, retry logic, SLA calculation, notification dispatch.
- `ChannelMetricsService` — aggregation (hourly, daily, weekly, monthly), SLA breach detection, trend analysis for capacity planning.
- `CtiIntegrationBridge` — webhook handler for incoming calls from OpenConnector, triggers agent notification, creates draft ContactMoment.
- `EmailIntakeHandler` — n8n webhook consuming email-intake events, creates ContactMoment with channel=email.
- `CallStatusUpdater` — websocket broadcaster or polling endpoint that notifies KCC agents of case pickup/completion status.
- REST endpoints under `/api/contact-moments`, `/api/routing-rules`, `/api/callback-requests`, `/api/kcc-metrics`, `/api/cti-webhook`.

## n8n Workflows
- **email-intake** — listens to klachten@gemeente.nl or department inboxes (Microsoft Graph polling or IMAP IDLE), creates ContactMoment with channel=email, attaches body + files, routes via RoutingEngine.
- **callback-monitor** — daily job scanning due callbacks; sends SMS/email 15 min before scheduled time, attempts call via CTI, logs outcome, retries on failure.
- **sla-monitor** — hourly scan of open ContactMoments; alerts handler at T-3 of SLA target, escalates to supervisor if breached, notifies originating KCC agent of delay.
- **sentiment-analysis** — post-call trigger (from CTI webhook) extracting transcript/notes, calls sentiment API (optional: Azure CognitiveServices), stores score for analytics.

## Integrations
- **OpenConnector** — CTI bridge (TAPI, WebRTC fallback), email polling (IMAP, Microsoft Graph), chat (Teams, WhatsApp Business).
- **OpenCatalogi** — BRP/KvK lookup on phone number / email for caller ID matching.
- **Procest** — case creation from routed ContactMoment, bidirectional status sync.
- **MyDash** — dashboard widgets for KCC volume/SLA metrics.
- **Docudesk** — callback confirmations, outbound contact templates.
- **Nextcloud Calendar** — callback scheduling (calendar invitations to agents).

## Risks & Mitigations
- **CTI lag** — caller on hold while BRP lookup completes; mitigation: show BRP result async on-screen without blocking, fall back to "Unknown" + manual lookup.
- **Routing rule conflicts** — multiple rules matching same input; mitigation: enforce priority order, test matrix on rule save, log rule-evaluation for ML feedback.
- **Privacy of contact recordings** — recordings must be encrypted at rest and in transit; mitigation: store in Nextcloud with ACL, comply with NEN 7510 (healthcare if applicable) and AVG art. 5+30.
- **Callback retry storms** — if retry logic is too aggressive, flood outbound calls; mitigation: exponential backoff, max 3 retries, configurable retry window per tenant.
- **SLA calculation edge cases** — holidays, weekend rules, shift boundaries; mitigation: centralize in `SlaCalculator` helper with comprehensive unit tests including Dutch holiday calendar.

## Standards
- **CTI (Computer Telephony Integration)** via TAPI or REST (Anywhere365, Genesys, Telecom1, KPN).
- **WebRTC** for browser-based softphone fallback.
- **Microsoft Graph API** for email integration with Exchange Online.
- **IMAP IDLE / JMAP** for near-realtime polling on non-Exchange mailboxes.
- **WCAG 2.1 AA** for KCC agent workplek (accessibility).
- **NEN 7510** for audit logging and secure handling of contact records.
- **AVG artikel 6 + 30** for lawful basis and processing register.
- **ETSI EN 301 549** for real-time communication accessibility.
- **Common Ground laag 5 (interactie)** — KCM (Klantcontact Management) standard in development by VNG Realisatie.
- **NL Design System** for agent workplek UI.
