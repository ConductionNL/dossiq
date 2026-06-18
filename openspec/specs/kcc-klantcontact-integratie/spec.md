---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# kcc-klantcontact-integratie Specification

## Purpose
Provides a customer-contact-centre (KCC) integration that surfaces caller context within one second of an inbound call (CTI popup with BRP identity, open cases, and contact history) and records every interaction as a structured ContactMoment. Omnichannel intake (phone, email, web form, chat, social media) feeds a routing engine that assigns each contact to the right team and suggests the best agent by workload, skill, and history, with callback scheduling and SLA tracking, status feedback to the originating agent, a volume/SLA reporting dashboard, an admin UI for routing rules, and NEN 7510/AVG-compliant data protection.
## Requirements
### Requirement: CTI Popup shows caller context within 1 second
Inbound phone calls MUST trigger immediate agent display of caller identity, open cases, and contact history without blocking the call.

#### Scenario: Caller matches in BRP
- **GIVEN** a phone call arrives from +31612345678
- **WHEN** the CTI webhook triggers at OpenConnector bridge
- **THEN** ContactMomentService creates a draft ContactMoment (`channel=phone, direction=inbound, status=new`)
- **AND** CtiIntegrationBridge queries OpenCatalogi BRP API with phone number
- **AND** within 1 second a popup is displayed to the available agent showing:
  - Caller name, address, date of birth (if BRP match found)
  - Up to 5 most recent open cases linked to this BSN
  - Last 3 ContactMoments with this caller (date, channel, subject, handler)
  - Suggested routing team based on last-case context
- **AND** if no BRP match, popup shows "Unknown caller" + manual lookup button

#### Scenario: Caller not in BRP (anonymous or unknown number)
- **GIVEN** a phone call from +31699999999 not matching BRP
- **WHEN** CTI triggers
- **THEN** popup shows "Unknown" with empty case/contact lists
- **AND** agent may manually enter customer name/phone for anonymous caller
- **AND** ContactMoment is created with `customerRef=null`

#### Scenario: Multiple open cases on same caller
- **GIVEN** caller has 7 open cases in Procest
- **WHEN** CTI popup displays
- **THEN** only 5 most-recent cases are shown
- **AND** agent can click "View all cases" to open full list in new tab

### Requirement: Routing rules automatically assign ContactMoment to correct team
Keyword and context matching MUST determine the destination domain and team without agent intervention.

#### Scenario: Keyword-based routing on subject
- **GIVEN** ContactMoment is created with `subject = "Kapotte lantaarnpaal Hoofdstraat 24"`
- **WHEN** RoutingEngine evaluates rules (priority order)
- **AND** rule "Openbare Werken → OBR" has `matchConditions` = [{type: keyword, value: "lantaarnpaal"}]
- **THEN** rule matches and ContactMoment is assigned `assignedTeam = "Beheer Openbare Ruimte"`, `assignedDomain = "openbare_werken"`
- **AND** a suggestion is displayed: "Voorgesteld: Openbare Werken / Beheer Openbare Ruimte"
- **AND** agent may accept (default action) or manually override

#### Scenario: Regex routing on complex patterns
- **GIVEN** a rule has `matchConditions` = [{type: regex, value: "WMO.*verzoek"}]
- **WHEN** ContactMoment subject = "WMO verzoek hulp huishouden"
- **THEN** regex matches and routing suggests Sociaal Domein team

#### Scenario: Time-of-day routing for specific team availability
- **GIVEN** a rule has `matchConditions` = [{type: channel, value: "phone"}, {type: time_of_day, value: "after_17:00"}]
- **WHEN** ContactMoment arrives at 17:15 on phone
- **THEN** rule matches and routes to out-of-hours escalation team

#### Scenario: Customer-type routing on business vs. citizen
- **GIVEN** a rule discriminates on `customer_type` = "bedrijf"
- **WHEN** ContactMoment.customerRef is a KvK number (business)
- **THEN** routing directs to Business Services team (if rule enabled)

#### Scenario: Rule priority conflict resolution
- **GIVEN** two rules both match:
  - Rule 1 (priority 1): "Paspoort → Burgerzaken"
  - Rule 2 (priority 2): "ID-kaart → Burgerzaken"
- **WHEN** ContactMoment subject = "Paspoort + ID-kaart verlenging"
- **THEN** the FIRST matching rule (lowest priority number) is applied
- **AND** rule-evaluation is logged for feedback on which rule fired

### Requirement: Agent suggestion ranks by workload, skill, and call history
Once team is assigned, the system MUST propose the best available agent.

#### Scenario: Select agent by lowest workload + skill match
- **GIVEN** ContactMoment routed to "Burgerzaken" team
- **AND** three agents in team are available:
  - Agent A: 8 open contacts, WMO-expert, English, last-contact with caller 2 months ago
  - Agent B: 5 open contacts, Paspoort-expert, English, no prior contact
  - Agent C: 12 open contacts, Burgerzaken-generalist, Dutch+English, last-contact 1 week ago
- **WHEN** RoutingEngine suggests agents
- **THEN** ranking = [B (lowest workload), C (recent contact), A (skill match on WMO but higher workload)]
- **AND** popup shows top 3 with motivation: "Anke van der Meer: 5 open zaken, Paspoort-expert, Nederlands+Engels"
- **AND** agent may click to assign directly or use first suggestion

#### Scenario: No available agent in primary team
- **GIVEN** all Burgerzaken agents are busy/break/offline
- **WHEN** RoutingEngine searches
- **THEN** escalation logic routes to fallback team (e.g., frontoffice generalist)
- **AND** agent receives higher-priority indicator ("Escalated — cover needed")

### Requirement: ContactMoment records capture full interaction context
Each contact MUST be permanently and structurally recorded.

#### Scenario: ContactMoment CRUD on phone intake
- **GIVEN** agent answers call from +31612345678 about "Paspoort verlenging"
- **WHEN** ContactMoment is auto-created with:
  ```
  {
    "id": "cm-2026-0001",
    "customerRef": "123456789",
    "customerPhone": "+31612345678",
    "channel": "phone",
    "direction": "inbound",
    "startedAt": "2026-05-20T09:15:00Z",
    "subject": "Paspoort verlenging",
    "kccAgentRef": "maria.santos",
    "assignedTeam": "Burgerzaken"
  }
  ```
- **THEN** ContactMoment is stored in OpenRegister with status `open`
- **AND** if agent hangs up at 09:27:30, `endedAt` and `durationSeconds` (750) are recorded
- **AND** agent may set `outcome` to resolved/transferred/callback_scheduled/escalated

#### Scenario: Email ContactMoment creation via n8n
- **GIVEN** email arrives at klachten@gemeente.nl
- **WHEN** n8n email-intake workflow triggers
- **AND** extracts sender email, subject, body
- **THEN** ContactMoment is created with:
  ```
  {
    "channel": "email",
    "direction": "inbound",
    "customerEmail": "burger@example.nl",
    "subject": "Mijn paspoort is verloren",
    "summary": "[email body first 500 chars]"
  }
  ```
- **AND** ContactMoment is routed via RoutingEngine
- **AND** assigned to email-queue for Burgerzaken team

#### Scenario: Transcript and sentiment storage
- **GIVEN** ContactMoment is linked to a call recording
- **WHEN** post-call, transcript is auto-generated (speech-to-text)
- **AND** sentiment score is calculated (0.0–1.0, positive bias)
- **THEN** `transcript` and `sentimentScore` fields are populated
- **AND** agent can review and edit transcript if needed

### Requirement: Omnichannel intake from email, web forms, chat, social media
Multiple input channels MUST feed the same ContactMoment workflow.

#### Scenario: Email intake from multiple department inboxes
- **GIVEN** routing rules are configured for burgerzaken@gemeente.nl, obr@gemeente.nl, etc.
- **WHEN** n8n polls these mailboxes via Microsoft Graph or IMAP
- **THEN** ContactMoments are created with channel=email
- **AND** routed based on keyword rules applied to subject + body
- **AND** assigned to the correct team's email queue

#### Scenario: Web form submission creates ContactMoment
- **GIVEN** a citizen submits a form via Procest form-engine
- **WHEN** form submission webhook fires
- **THEN** ContactMoment is created with `channel=web_form`
- **AND** form fields (name, email, subject, message) populate ContactMoment fields
- **AND** routed via RoutingEngine to correct team

#### Scenario: Chat via Teams or WhatsApp
- **GIVEN** OpenConnector bridge is configured for Teams or WhatsApp Business
- **WHEN** citizen sends chat message
- **THEN** ContactMoment created with `channel=chat`
- **AND** message thread is linked to ContactMoment `transcript`
- **AND** routed to chat-capable agents only

### Requirement: Callback scheduling and SLA tracking
Agents MUST be able to schedule callbacks and the system MUST monitor and attempt them.

#### Scenario: Agent schedules callback
- **GIVEN** agent is handling ContactMoment about "Paspoort"
- **WHEN** agent clicks "Schedule Callback" button
- **THEN** modal opens with:
  - Customer phone (auto-filled)
  - Preferred time window (e.g., "Tomorrow 14:00–16:00")
  - Preferred agent dropdown (defaults to self)
  - Reason text field
- **AND** upon save, CallbackRequest is created with `status=scheduled`
- **AND** calendar invitation is sent to assigned agent
- **AND** original ContactMoment is linked via `callbackRequestRef`

#### Scenario: Callback reminder 15 minutes before scheduled time
- **GIVEN** CallbackRequest scheduled for 2026-05-21 14:30
- **WHEN** n8n callback-monitor job runs at 14:15
- **THEN** SMS or push notification is sent to assigned agent
- **AND** notification includes customer name, phone, reason

#### Scenario: Callback attempt and retry on no-answer
- **GIVEN** CallbackRequest at 14:30, assigned agent is Agent B
- **WHEN** n8n attempts call via CTI at scheduled time
- **AND** call connects but customer does not pick up (5 rings)
- **THEN** call is ended, `status` remains `scheduled`, `attemptCount` incremented to 1
- **AND** `nextAttemptAt` is set to 14:45 (15 min later)
- **AND** maximum 3 retries are allowed; after 3, status = `failed`, supervisor is notified

#### Scenario: Successful callback attempt
- **GIVEN** callback is attempted and customer picks up
- **WHEN** agent or IVR interacts with customer for 2 minutes
- **THEN** call is logged, `status = completed`, `attemptCount = 1`
- **AND** new ContactMoment is created for the callback call (direction=outbound)
- **AND** original inbound ContactMoment is linked to callback ContactMoment

### Requirement: Status feedback to originating KCC agent
When a routed case is picked up or completed, the KCC agent MUST be notified.

#### Scenario: Notification when backoffice handler picks up case
- **GIVEN** ContactMoment cm-001 is routed to Burgerzaken, assigned to Agent B
- **WHEN** Agent B opens the linked Procest case in their workload
- **AND** case status transitions to `In behandeling`
- **THEN** a notification is sent to original KCC agent (Agent A):
  - "Anke van der Meer has picked up case ZK-2026-0123"
  - Link to view case status

#### Scenario: Notification on case completion
- **GIVEN** case ZK-2026-0123 (linked to ContactMoment cm-001) is completed
- **WHEN** case status = `Afgehandeld`
- **THEN** KCC agent receives notification:
  - "Case ZK-2026-0123 completed by Anke: Paspoort approved"
  - Agent can choose to call customer back with summary

#### Scenario: Realtime notification via websocket (if agent workplek open)
- **GIVEN** KCC agent has the contact detail page open in browser
- **WHEN** linked case status changes
- **THEN** notification appears in-page without page reload
- **AND** a sound/badge alert is triggered

### Requirement: Volume and SLA reporting dashboard
Team leads and managers MUST see KCC performance metrics and capacity trends.

#### Scenario: Weekly dashboard view
- **GIVEN** team lead opens the KCC Dashboard for week 2026-05-12 to 2026-05-18
- **WHEN** page loads
- **THEN** displays:
  - **Contact Volume** (bar chart): inbound/outbound by channel (phone: 1203, email: 487, chat: 156, web: 321)
  - **Average Handle Time** (line chart): trend over week (target 2.8 min, current 3.2 min)
  - **First-Contact Resolution** (pie): 68% resolved on first contact, 32% require follow-up
  - **Top 10 Categories** (bar): Paspoort (203), ID-kaart (156), Inschrijving (145), etc.
  - **SLA Status** (KPI cards): 98.2% on-time (target 98%), 8 breaches (target <5)
  - **Agent Occupancy** (heat map): shows utilization % by agent and hour
  - **Capacity Forecast** (trend): projects load for next 7 days based on daily averages

#### Scenario: Filter dashboard by team, agent, channel
- **GIVEN** dashboard is open
- **WHEN** user clicks filter dropdown for Team = "Burgerzaken"
- **THEN** all charts update to show only Burgerzaken contacts
- **AND** filter can be combined (Team + Channel)

#### Scenario: Export to Excel/PDF for reporting
- **GIVEN** dashboard showing 2026-05-12 to 2026-05-18 data
- **WHEN** user clicks "Export" button
- **THEN** Excel file is generated with:
  - Summary sheet (KPI cards, charts)
  - Detailed data sheet (per-contact rows)
  - Team breakdown sheet
- **AND** PDF export generates a formatted report suitable for management

#### Scenario: Predict next week's capacity
- **GIVEN** historical data shows average 250 contacts/day
- **AND** Monday 2026-05-26 is a holiday
- **WHEN** forecast is generated
- **THEN** predicts week 2026-05-19 to 2026-05-25:
  - Tue–Fri: ~250 per day (normal)
  - Mon (holiday): 0 (closed)
  - Sat–Sun: 0 (closed)
  - Total: ~1000 for week (vs. normal 1250)

### Requirement: Configuration and admin UI
Team leads MUST be able to create and modify routing rules.

#### Scenario: Create new routing rule via admin UI
- **GIVEN** team lead is in Settings > Routing Rules
- **WHEN** clicks "Create Rule"
- **THEN** form opens with fields:
  - Rule name: "WMO → Sociaal Domein"
  - Priority: 5
  - Match conditions (add/remove rows):
    - Type: Keyword, Value: "WMO"
    - Type: Keyword, Value: "hulp"
  - Assigned domain: "Sociaal Domein"
  - Assigned team: "WMO Support Team"
  - Enabled: (toggle)
- **AND** upon save, rule is created and immediately active

#### Scenario: Reorder rules by priority
- **GIVEN** 10 rules are configured
- **WHEN** team lead drags rule 3 to position 1 in the priority list
- **THEN** rule priorities are recalculated (rule moves to priority=1, others shift down)
- **AND** change is saved and takes effect immediately

### Requirement: Data privacy and compliance
Contact data MUST be protected and logged per NEN 7510 and AVG.

#### Scenario: Encrypt call recordings at rest and in transit
- **GIVEN** ContactMoment has a transcript from recorded call
- **WHEN** transcript is stored
- **THEN** encryption is applied (AES-256 at rest)
- **AND** transmission to storage is encrypted (TLS)
- **AND** access is logged in audit trail

#### Scenario: Audit trail on sensitive field access
- **GIVEN** agent views a ContactMoment with sentimentScore or BRP data
- **WHEN** user opens record
- **THEN** access is logged with user, timestamp, and action
- **AND** compliance reports can extract audit log per record

