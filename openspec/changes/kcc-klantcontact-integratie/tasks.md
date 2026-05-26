# Tasks

- [ ] TASK-KCC-01: Add `contactMoment`, `routingRule`, `kccAgent`, `contactQueue`, `callbackRequest`, and `channelVolumeMetric` schemas to `procest_register.json` and register config keys in `SettingsService::SLUG_TO_CONFIG_KEY`.

- [ ] TASK-KCC-02: Implement `ContactMomentService` with CRUD, linking to Procest cases, transcript storage, sentiment score fields, and query methods for agent workload/history lookup.

- [ ] TASK-KCC-03: Implement `RoutingEngine` with rule evaluation (keyword, regex, customer-type, time-of-day matching), priority ordering, and candidate-agent ranking by workload + skill + call history.

- [ ] TASK-KCC-04: Implement `KCCAgentService` with status transitions (available/busy/break/after_call_wrap/offline), skill catalog, workload tracking, and availability queries.

- [ ] TASK-KCC-05: Implement `CallbackService` with scheduling, SLA calculation (respecting Dutch holidays), retry logic (exponential backoff, max 3 attempts), and notification dispatch.

- [ ] TASK-KCC-06: Implement `ChannelMetricsService` with hourly/daily/weekly/monthly aggregation, SLA breach detection, first-contact-resolution calculation, and trend analysis for capacity forecasting.

- [ ] TASK-KCC-07: Build `CtiIntegrationBridge` webhook handler for incoming calls from OpenConnector; creates draft ContactMoment, triggers BRP lookup via OpenCatalogi, broadcasts CTI popup to available agents.

- [ ] TASK-KCC-08: Build `EmailIntakeHandler` webhook endpoint consumed by n8n; creates ContactMoment from email (channel=email), extracts sender/subject/body, routes via RoutingEngine.

- [ ] TASK-KCC-09: Implement `CallStatusUpdater` service for bidirectional sync: broadcast case status changes to originating KCC agent via websocket or polling endpoint.

- [ ] TASK-KCC-10: Build `KCCWorkplaceToolbar.vue` CTI popup showing caller ID (BRP match result), up to 5 open cases, last 3 contacts, and routing suggestion with agent top-3 list.

- [ ] TASK-KCC-11: Build `RoutingRuleAdmin.vue` with CRUD for routing rules, priority reordering, condition builder (keyword/regex/customer-type/time-of-day), and team/domain assignment.

- [ ] TASK-KCC-12: Build `CallbackScheduler.vue` modal for scheduling callbacks; time-window picker, preferred-agent dropdown, reason text, and confirmation.

- [ ] TASK-KCC-13: Build `KCCDashboard.vue` with real-time contact volume (bar chart by channel), average handle time (line trend), first-contact resolution (pie), top-10 categories (bar), SLA status (KPI cards), agent occupancy heat map, and capacity forecast.

- [ ] TASK-KCC-14: Build `AgentStatusPanel.vue` for agent availability toggle, skill tags display, current workload indicator, and current case preview.

- [ ] TASK-KCC-15: Build `ContactDetail.vue` showing full contact record (transcript if available, linked case, tags, sentiment, related contacts, escalation/callback history) with edit capability for agent notes.

- [ ] TASK-KCC-16: Create REST controller `ContactMomentController` with endpoints:
  - `GET /api/contact-moments` (list with filters: channel, status, agent, date range)
  - `POST /api/contact-moments` (create)
  - `GET /api/contact-moments/{id}` (detail)
  - `PUT /api/contact-moments/{id}` (update)
  - `GET /api/contact-moments/{id}/related` (related contacts)

- [ ] TASK-KCC-17: Create REST controller `RoutingRuleController` with endpoints:
  - `GET /api/routing-rules` (list)
  - `POST /api/routing-rules` (create)
  - `PUT /api/routing-rules/{id}` (update)
  - `DELETE /api/routing-rules/{id}` (delete)
  - `PUT /api/routing-rules/reorder` (priority reordering)
  - `POST /api/routing-rules/{id}/test` (test rule with sample ContactMoment)

- [ ] TASK-KCC-18: Create REST controller `RoutingEngineController` with endpoint:
  - `POST /api/routing/evaluate` (evaluate rules on ContactMoment, return suggested team + top-3 agents with motivation)

- [ ] TASK-KCC-19: Create REST controller `CallbackController` with endpoints:
  - `POST /api/callback-requests` (create)
  - `GET /api/callback-requests` (list)
  - `PUT /api/callback-requests/{id}` (update status, reschedule)
  - `POST /api/callback-requests/{id}/cancel` (cancel callback)

- [ ] TASK-KCC-20: Create REST controller `KCCMetricsController` with endpoints:
  - `GET /api/kcc-metrics/volume` (period, channel filters)
  - `GET /api/kcc-metrics/sla` (SLA status, breaches by team)
  - `GET /api/kcc-metrics/forecast` (7-day capacity forecast)
  - `GET /api/kcc-metrics/export` (Excel/PDF export)

- [ ] TASK-KCC-21: Add n8n workflow `email-intake`: listen to department mailboxes via Microsoft Graph or IMAP IDLE, create ContactMoment (channel=email), route via RoutingEngine.

- [ ] TASK-KCC-22: Add n8n workflow `callback-monitor`: daily job scanning due CallbackRequests, send SMS/email reminder 15 min before, attempt call via CTI, log outcome, retry on failure (max 3 attempts).

- [ ] TASK-KCC-23: Add n8n workflow `sla-monitor`: hourly scan of open ContactMoments, alert handler at T-3 of SLA target, escalate to supervisor if breached, notify originating KCC agent.

- [ ] TASK-KCC-24: Add n8n workflow `sentiment-analysis`: post-call trigger extracting transcript/notes, call sentiment API (optional: Azure CognitiveServices), store score on ContactMoment.

- [ ] TASK-KCC-25: Implement `SlaCalculator` helper with:
  - Working-day math (respecting Dutch public holidays)
  - SLA deadline calculation for each channel (phone: 2.8 min, email: 2 working days, chat: 1 hour)
  - Breach detection and escalation logic
  - Unit tests covering edge cases (holidays, weekends, shift boundaries)

- [ ] TASK-KCC-26: Integrate with OpenConnector CTI bridge:
  - Map incoming call events to ContactMoment creation
  - Trigger BRP lookup via OpenCatalogi
  - Broadcast CTI popup to available agents
  - Support TAPI and REST-based CTI systems

- [ ] TASK-KCC-27: Integrate with OpenCatalogi for BRP/KvK lookup:
  - Query by phone number or email
  - Cache results briefly (5 min) to reduce API calls
  - Handle "not found" gracefully (Unknown caller)

- [ ] TASK-KCC-28: Integrate with Procest case API:
  - Link ContactMoment to case via `caseRef`
  - Auto-create case from routed ContactMoment (if needed)
  - Subscribe to case status changes and broadcast to KCC agent

- [ ] TASK-KCC-29: Add English + Dutch i18n strings for:
  - All routing rule and callback UI labels
  - Dashboard chart legends and KPI card titles
  - Notification templates (reminder, escalation, completion)
  - Agent status options, team names, skill tags

- [ ] TASK-KCC-30: Add tenant-admin UI for KCC configuration:
  - Settings > Routing Rules (CRUD, reorder, test)
  - Settings > SLA Thresholds (per channel, per team)
  - Settings > Agent Skills (catalog of skill tags)
  - Settings > Email Intake (mailbox mappings, polling schedule)

- [ ] TASK-KCC-31: Add audit logging for sensitive operations:
  - Access to ContactMoments with BRP data or recordings
  - Routing rule changes
  - Callback scheduling/cancellation
  - Agent status changes
  - Export/download of metrics or contact lists

- [ ] TASK-KCC-32: Create seed/demo data:
  - 5 RoutingRules (Paspoort, ID-kaart, Openbare Werken, WMO, Inschrijving)
  - 3 KCCAgents with different skills and status
  - 5 ContactMoments spanning phone/email/chat with varying outcomes
  - 2 CallbackRequests (one scheduled, one completed)
  - Weekly ChannelVolumeMetric for demo dashboard

- [ ] TASK-KCC-33: Write unit tests:
  - RoutingEngine: rule matching (keyword, regex, time-of-day), priority ordering, agent ranking
  - SlaCalculator: deadline math, holiday handling, working-day logic
  - CallbackService: scheduling, retry logic, SLA calculation
  - ContactMomentService: CRUD, linking, query methods

- [ ] TASK-KCC-34: Write integration tests:
  - CTI popup flow (incoming call → BRP lookup → popup display)
  - Email intake flow (email → n8n → ContactMoment → routed)
  - Case status feedback (case status change → KCC agent notification)
  - Callback attempt (scheduled time → CTI call → outcome logging)

- [ ] TASK-KCC-35: Document API endpoints:
  - OpenAPI/Swagger spec for all ContactMoment, Routing, Callback, Metrics endpoints
  - Example requests and responses
  - Authentication and authorization requirements

- [ ] TASK-KCC-36: Document n8n workflows:
  - Webhook endpoints and expected payloads
  - Error handling and retry strategies
  - Integration points with ContactMomentService and CallbackService

- [ ] TASK-KCC-37: Performance testing and optimization:
  - Load test RoutingEngine with 1000+ rules
  - Load test metrics aggregation (hourly, daily, weekly)
  - Query optimization for ContactMoment searches (indexes on channel, status, team, date)
  - Cache strategy for RoutingRules (hot-reload on change)

- [ ] TASK-KCC-38: Security review:
  - Validate and sanitize all user inputs (routing rule conditions, callback times)
  - Enforce role-based access control (KCC agents, team leads, admin)
  - Test encryption of call recordings and transcript data
  - Verify audit logging covers all sensitive operations per NEN 7510 and AVG

- [ ] TASK-KCC-39: Accessibility audit:
  - WCAG 2.1 AA testing of CTI popup, routing admin, dashboard
  - Screen reader testing with VoiceOver/NVDA
  - Keyboard navigation for all forms and widgets
  - Color contrast checks on dashboard charts

- [ ] TASK-KCC-40: User acceptance testing (UAT):
  - KCC agent workflow: receive call → view context → route → track status
  - Team lead workflow: review dashboard → filter by team → export report
  - Admin workflow: create routing rule → test → deploy
  - Collect feedback and iterate on UI/UX
