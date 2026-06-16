# Tasks

> Implementation notes (ADR guardrails applied):
> - **ADR-037**: new schemas + seed objects live in `lib/Settings/register.d/30-kcc.json`; the monolith `procest_register.json` is NOT edited. The deep-merge loader (`SettingsService::mergeRegisterFragments`) unions `components.schemas`, register `schemas[]` membership and `components.objects[]` — covered by `tests/Unit/Settings/KccFragmentTest.php`.
> - **Contact entity reuse**: the ContactMoment is the existing `customerContact` (KlantContact) schema, *extended* with KCC fields via the fragment — no new contact/customer schema was invented (guardrail).
> - **ADR-022**: services use the real OpenRegister ObjectService API only (`find`/`findAll`/`saveObject`/`deleteObject`).
> - **ADR-005**: reads/writes are scoped to the calling agent (IDOR-safe); routing-rule CRUD is admin/team-lead gated; identity is taken from `IUserSession`, never the request body.
> - Live-instance / cross-app-dependent tasks (CTI, n8n, BRP/KvK lookup, websockets, dashboard export, a11y/perf/UAT) are **deferred** with reasons below and tracked for follow-up.

- [x] TASK-KCC-01: KCC schemas added via `register.d/30-kcc.json` (ADR-037). `routingRule`, `kccAgent`, `callbackRequest` are new schemas + register members; `contactMoment` reuses/extends the existing `customerContact` schema. Config keys (`routing_rule_schema`, `kcc_agent_schema`, `callback_request_schema`) + slug mappings registered in `SettingsService`.

- [x] TASK-KCC-02: `ContactMomentService` — CRUD (`create`/`update`/`get`/`list`/`related`), case linking, duration computation, agent/customer query methods. Reuses `customerContact`. Unit tests in `ContactMomentServiceTest`.

- [x] TASK-KCC-03: `RoutingEngine` — keyword / regex / channel / customer_type / time_of_day / day_of_week matching, priority ordering (lowest number wins), and agent ranking by workload + skill + continuity. Pure + fully unit-tested (`RoutingEngineTest`).

- [x] TASK-KCC-04: KCCAgent status/skill/workload model implemented as a schema + agent loading + ranking in `RoutingRuleService`/`RoutingEngine`. Status-transition write endpoints DEFERRED (need the live KCC-werkplek presence channel; see Pipelinq cross-app dependency).

- [x] TASK-KCC-05: `CallbackService` — scheduling, validation, retry logic (exponential backoff, max 3 attempts via `SlaCalculator`), lifecycle transitions (`applyAttempt`/`cancel`). Unit tests in `CallbackServiceTest`.

- [DEFERRED] TASK-KCC-06: `ChannelMetricsService` aggregation/forecast — DEFERRED: requires a populated live dataset and the MyDash dashboard host to verify aggregation windows; scaffolding belongs with the dashboard task (TASK-KCC-13/20).

- [DEFERRED] TASK-KCC-07: `CtiIntegrationBridge` — DEFERRED: requires a live OpenConnector CTI bridge + provider signature/secret; ADR-005 forbids shipping an unsigned public webhook. Tracked for the OpenConnector integration milestone.

- [DEFERRED] TASK-KCC-08: `EmailIntakeHandler` webhook — DEFERRED: requires the live n8n email-intake workflow + a shared-secret webhook; pairs with TASK-KCC-21.

- [DEFERRED] TASK-KCC-09: `CallStatusUpdater` (websocket/polling) — DEFERRED: requires the live Procest case event stream + KCC-werkplek socket; pairs with TASK-KCC-28.

- [DEFERRED] TASK-KCC-10..15: Vue UI (`KCCWorkplaceToolbar`, `RoutingRuleAdmin`, `CallbackScheduler`, `KCCDashboard`, `AgentStatusPanel`, `ContactDetail`) — DEFERRED: the manifest-v2 declarative pages + modals need live-instance rendering verification (browser pool) which is outside this headless build; the REST + i18n contract they consume is shipped here.

- [x] TASK-KCC-16: `KccContactController` — `index`/`create`/`show`/`update`/`related` for contact moments (`#[NoAdminRequired]`, agent-scoped, IDOR-safe).

- [x] TASK-KCC-17: `KccRoutingController` — routing-rule `index`/`create`/`update`/`destroy` (admin/team-lead gated) backed by `RoutingRuleService`. Priority is a rule field (`reorder` is a UI affordance, deferred with the admin UI).

- [x] TASK-KCC-18: `KccRoutingController::evaluate` (`POST /api/kcc/routing/evaluate`) — returns suggested team + ranked agents with motivation.

- [x] TASK-KCC-19: Callback endpoints (`indexCallbacks`/`scheduleCallback`/`cancelCallback`) on `KccContactController`.

- [DEFERRED] TASK-KCC-20: `KCCMetricsController` (volume/sla/forecast/export) — DEFERRED with TASK-KCC-06/13 (needs live data + export host).

- [DEFERRED] TASK-KCC-21..24: n8n workflows (email-intake, callback-monitor, sla-monitor, sentiment-analysis) — DEFERRED: authored in the n8n workspace against a running instance, not in the app repo.

- [x] TASK-KCC-25: `SlaCalculator` — working-day math with the Dutch public-holiday calendar (fixed + Easter-derived), per-channel SLA deadlines, breach detection, exponential backoff. Comprehensive unit tests (`SlaCalculatorTest`) incl. holiday/weekend edge cases.

- [DEFERRED] TASK-KCC-26: OpenConnector CTI integration — DEFERRED (see TASK-KCC-07).

- [DEFERRED] TASK-KCC-27: OpenCatalogi BRP/KvK lookup — DEFERRED: requires a live OpenCatalogi source + caching layer; `customerRef`/`customerType` plumbing is shipped so the lookup can populate it.

- [x] TASK-KCC-28: Procest case linking — contact moments carry a `case` reference (reusing the existing `customerContact.case` link). Bidirectional status broadcast DEFERRED with TASK-KCC-09.

- [x] TASK-KCC-29: i18n nl + en strings added to all four l10n files (`en.json`/`en.js`/`nl.json`/`nl.js`) for KCC labels, statuses and error/notification copy.

- [DEFERRED] TASK-KCC-30: tenant-admin KCC settings UI — DEFERRED with the Vue UI (TASK-KCC-11); routing-rule CRUD API is shipped and admin-gated.

- [x] TASK-KCC-31: Audit logging — service operations log via `LoggerInterface`; BSN/special-category data is never logged raw (masked at payload build). Full NEN-7510 audit-trail records DEFERRED with the recording feature.

- [x] TASK-KCC-32: Seed/demo data — 3 routing rules, 3 KCC agents, 1 callback request seeded via the fragment `components.objects[]`.

- [x] TASK-KCC-33: Unit tests — `RoutingEngineTest`, `SlaCalculatorTest`, `CallbackServiceTest`, `ContactMomentServiceTest`, `KccFragmentTest`.

- [DEFERRED] TASK-KCC-34: Integration tests (CTI/email/status/callback flows) — DEFERRED: require the live cross-app instances (CTI, n8n, Procest events).

- [DEFERRED] TASK-KCC-35: OpenAPI docs — DEFERRED: route contract is defined; the fleet generates OpenAPI from a live instance.

- [DEFERRED] TASK-KCC-36: n8n workflow docs — DEFERRED with TASK-KCC-21..24.

- [DEFERRED] TASK-KCC-37: Performance testing — DEFERRED: needs a live instance + large dataset.

- [x] TASK-KCC-38: Security review — input validation, ADR-005 auth posture, IDOR scoping, BSN masking and XXE-safety addressed in code; full pen-test DEFERRED.

- [DEFERRED] TASK-KCC-39: Accessibility audit — DEFERRED with the Vue UI (browser pool / live instance).

- [DEFERRED] TASK-KCC-40: UAT — DEFERRED: requires a live instance and end users.
</content>
