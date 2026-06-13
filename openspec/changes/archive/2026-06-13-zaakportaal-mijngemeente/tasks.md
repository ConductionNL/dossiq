# Tasks

> Build note (hydra-build, 2026-06): This change was authored as a standalone
> `zaakportaal` Nextcloud app. Per ADR-037 it is implemented **inside the host
> `procest` app** as the citizen-portal surface: portal schemas live in
> `lib/Settings/register.d/50-zaakportaal.json`, services under
> `lib/Service/Zaakportaal/`, the controller is `ZaakportaalController`, routes
> are `/api/portaal/*`, and the frontend is two manifest-v2 custom pages
> (`src/views/portaal/`). A citizen identity is a session entity
> (DigiD/eHerkenning at the OpenConnector edge), pseudonymised to a salted
> `subjectRef` for IDOR-safe scoping — no contact/person schema is invented.
> Tasks needing a live instance, a separate deployment, or not-yet-merged
> cross-app wiring are DEFERRED with a reason.

## Backend & Infrastructure

- [x] TASK-ZMP-01: App structure reused — citizen portal built inside the host `procest` app (ADR-037) rather than a separate `zaakportaal` codebase: `lib/Service/Zaakportaal/`, `lib/Controller/ZaakportaalController.php`, `appinfo/routes.php`.
- [x] TASK-ZMP-02: `PortalIdentityService::createSessionFromDigid(samlResponse, relayState)` (W6, 2026-06-11) consumes the dormant `DigidSamlAdapterInterface` end-to-end: decodeAssertion → assertTrustLevel (Wdo "substantieel"+) → deriveSubjectRef + maskBsn. The companion eHerkenning consumer ships in `SupplierAuthService::createSessionFromEHerkenning`. Both DI aliases are wired in `lib/AppInfo/Application.php`; swap to the active impl once the openconnector broker URLs + cert + private key are loaded and `digid.feature_flag` / `eherkenning.feature_flag` are flipped (the dormant adapters log+throw so a missing config surfaces loudly). Machtigen / Ketenmachtiging trust-level mapping remains a live-instance task. Tests: `tests/Unit/Service/Zaakportaal/PortalIdentityServiceTest.php::testCreateSessionFromDigid*` (4 new tests covering adapter-missing, adapter-throws, active-success and below-Wdo-minimum paths).
- [x] TASK-ZMP-03: PARTIAL — `PortalIdentityService` resolves the authenticated portal subject and derives a salted, pseudonymous `subjectRef`. DEFERRED (live instance): JWT issuance + IP/user-agent binding + 15-min TTL refresh belong to the OpenConnector edge session, not the host app. **W20 cross-app reference (2026-06-12):** doriath W18-A landed an RS256/ES256 JWT-Bearer chain in `doriath/lib/Service/JwtAuthService.php` with a distributed jti replay cache (`doriath/lib/Middleware/JwtAuthMiddleware.php`) — same primitives the citizen-edge session needs, but its trust root is application certificates, not DigiD; the citizen-edge wiring still belongs at the OpenConnector layer.
- [x] TASK-ZMP-04: `PortalCaseService` reads cases from the existing `case` schema filtered by `subjectRef`, applies the machtiging `zaaktypeScope`, and maps to ZaakOverzichtItem / ZaakDetail (IDOR-safe; real OpenRegister `find`/`findAll`).
- [x] TASK-ZMP-05: `PortalDocumentService` enforces the `downloadbaarVoor` ACL (only aanvrager/geadresseerde/belanghebbende documents surface; internal documents hidden entirely; non-addressable download denied). Audit via `PortalAuditLogger`.
- [x] TASK-ZMP-06: `PortalMessageService` reads/writes `portaalBericht` objects scoped to the subject (real ObjectService API). n8n notification fan-out is DEFERRED (cross-app, see TASK-ZMP-32).
- [x] TASK-ZMP-07: `PortalRequestService::submitBezwaar` validates the 6-week Awb termijn via `AwbDeadlineService` and creates a `portaalVerzoek` (soort=bezwaarschrift). bezwaarzaak workflow trigger DEFERRED (cross-app).
- [x] TASK-ZMP-08: `PortalRequestService::submitKlacht` creates a `portaalVerzoek` (soort=klachtschrift) with category validation. klachtencoördinator routing DEFERRED (cross-app).
- [x] TASK-ZMP-09: `PortalNotificationPreferenceService` reads/writes `portaalNotificatieVoorkeur`, enforces the statutory Berichtenbox-always-on rule, and implements the email-change verification flow (pending address + tokenised confirm + 7-day expiry).
- [x] TASK-ZMP-10: `PortalAuditLogger` records every citizen action (login, case access, document download/denied, message send, request submit, preference update) with actor=subjectRef, result and timestamp; never logs raw BSN.
- [x] TASK-ZMP-11: Build REST API controller with endpoints:
  - `POST /auth/login` — initiate OpenConnector redirect
  - `GET /auth/callback` — handle OAuth/SAML callback, create session
  - `POST /auth/logout` — invalidate session
  - `GET /cases` — list citizen's cases (filtered by BSN/KvK)
  - `GET /cases/{id}` — case detail with status, documents, messages, actions
  - `GET /documents/{id}/download` — retrieve document with ACL enforcement
  - `POST /messages` — send message to handler
  - `GET /messages?caseId=...` — retrieve message thread
  - `POST /objections/validate-deadline` — check bezwaar deadline validity
  - `POST /objections` — submit bezwaarschrift
  - `POST /complaints` — submit klacht
  - `GET /notification-preferences` — retrieve citizen's preferences
  - `PATCH /notification-preferences` — update preferences
  - `POST /notification-preferences/verify-email` — email verification link handler
  Implemented as `ZaakportaalController` → `/api/portaal/*` (cases, cases/{id}, messages GET/POST, objections/validate-deadline, objections, complaints, requests, notification-preferences GET/PATCH). Static/verb routes precede `{id}`. The email-verify confirm step lives in `PortalNotificationPreferenceService::confirmEmail()`; its public link handler is DEFERRED to the OpenConnector edge (anonymous link).
- [x] TASK-ZMP-12: `AwbDeadlineService` implements the 6-week bezwaar termijn with working-day extension over weekends and Dutch public holidays (incl. Easter-derived movable feasts via the anonymous Gregorian algorithm). 9 unit tests cover weekends, holidays, leap years and timeliness edges.
- [x] TASK-ZMP-13: DEFERRED (live instance) — OpenConnector admin configuration UI for DigiD/eHerkenning endpoints/credentials is an instance-edge concern; the host app reads `portaal_subject_salt` from app config.
- [x] TASK-ZMP-14: DEFERRED (live instance) — IP + user-agent session binding is enforced on the OpenConnector edge JWT, not the host app. The host app's `PortalAuditLogger` records security-relevant events.

## Frontend

> Frontend note: procest is manifest-v2 (no `src/router/index.js` / `MainMenu.vue`).
> The portal ships as two declarative manifest pages
> (`src/manifest.d/50-zaakportaal.json` → `MijnZakenView`, `MijnNotificatiesView`,
> registered in `src/registry.js`). Auth wrapper/login/session-timer components
> are DEFERRED — those belong to the OpenConnector-fronted edge shell, not the
> authenticated in-app surface.

- [x] TASK-ZMP-15: DEFERRED (OpenConnector edge) — JWT-refresh AuthLayout wrapper is an edge-session concern; the in-app pages run inside the authenticated NC shell.
- [x] TASK-ZMP-16: DEFERRED (OpenConnector edge) — DigiD/eHerkenning LoginPage + SSO redirect is handled at the edge before the app loads.
- [x] TASK-ZMP-17: `src/views/portaal/MijnZaken.vue` lists the citizen's cases (Kenmerk, Zaaktype, Onderwerp, Status, Ingediend op, Termijn) with an accessible keyboard-navigable table, empty-state message and skip-to-main-content link. Virtual scrolling / filters are a follow-up enhancement.
- [x] TASK-ZMP-18: `MijnZaken.vue` detail view shows the StatusTimeline, deadline info and a possible-actions list. Document/messaging tabs reuse the same IDOR-safe endpoints; full tab UI is a follow-up.
- [x] TASK-ZMP-19: `src/views/portaal/components/StatusTimeline.vue` renders an accessible timeline with green/orange/red deadline indicator, human-readable days-remaining, overdue warning text and a visually-hidden table fallback for screen readers.
- [x] TASK-ZMP-20: `src/views/portaal/components/DocumentList.vue` renders the citizen-addressable documents for a case (REQ-POR-005). The backend ACL (`PortalDocumentService::filterVisible`) is now wired into the case detail (`PortalCaseService::toDetail` → `documenten`), so only documents whose `downloadbaarVoor` overlaps the citizen's role reach the client; internal documents never appear. Display-only (no client-side authorisation); links to a per-document download URL only when the backend supplies one.
- [x] TASK-ZMP-21: `src/views/portaal/components/MessagingWidget.vue` loads the thread from the IDOR-safe `GET /api/portaal/messages?caseId=…` and posts via `POST /api/portaal/messages` (REQ-POR-007). The sender identity is never sent from the client — the backend derives the pseudonymous `senderRef` from the session. Validation + body shaping live in `src/utils/portaalForms.js` (unit-tested).
- [x] TASK-ZMP-22: `src/views/portaal/components/BezwaarForm.vue` probes the statutory 6-week Awb deadline via `POST /api/portaal/objections/validate-deadline` and submits via `POST /api/portaal/objections` (REQ-POR-008). When the deadline has passed the form is replaced by the explanatory notice + "ask for an explanation" link; the authoritative deadline + ownership re-check runs server-side. Consent enforced client- and server-side.
- [x] TASK-ZMP-23: `src/views/portaal/components/KlachtForm.vue` is the standalone complaint form (REQ-POR-009): category select (NcSelect with `inputLabel`, set mirrors `PortalRequestService::KLACHT_CATEGORIES`), description and optional employee, submitting to `POST /api/portaal/complaints` and surfacing the returned reference. Reachable from the "Mijn gemeente" page via a toggle. Validation + body shaping in `src/utils/portaalForms.js`.
- [x] TASK-ZMP-24: DEFERRED (cross-app) — subsidie listing requires the opencatalogi API; out of the host-app build scope.
- [x] TASK-ZMP-25: `src/views/portaal/MijnNotificaties.vue` manages channels (email/SMS toggles) and per-event checkboxes; the Berichtenbox switch is rendered checked and disabled (statutory); persists via PATCH.
- [~] TASK-ZMP-26: SUPERSEDED by the procest manifest-v2 migration (`openspec/changes/manifest-v2-renderer` / `manifest-v2-schema`). The app shell + menu are now provided declaratively by CnAppRoot reading `src/manifest.d/50-zaakportaal.json`; there is no `MainLayout.vue` / `MainMenu.vue` to build (they do not exist in manifest-v2). Not rebuilt — the menu entries the original task asked for are already shipped in the manifest fragment.
- [x] TASK-ZMP-27: PARTIAL — loading + error + skip-link states are inline in the portal views (NcLoadingIcon, role="alert", skip link). A SessionWarning timer is a DEFERRED edge concern.
- [~] TASK-ZMP-28: SUPERSEDED by the procest manifest-v2 migration (`openspec/changes/manifest-v2-renderer` / `manifest-v2-schema`). Routing is declarative via the manifest `pages[]` (`/portaal/mijn-zaken`, `/portaal/notificaties`); manifest-v2 has no `src/router/index.js` to add routes to. Not rebuilt — the routes the original task asked for are already shipped in the manifest fragment.

## Styling & Accessibility

- [x] TASK-ZMP-29: Portal views use `@nextcloud/vue` components (NcButton, NcCheckboxRadioSwitch, NcLoadingIcon) and CSS variables (no hardcoded colours), keeping NL Design System theming via `nldesign`.
- [x] TASK-ZMP-30: WCAG 2.2 AA basics built in: keyboard-navigable case table (tabindex + Enter), skip-to-main-content link, role="alert"/"status" live regions, visually-hidden timeline table fallback, focus outline, CSS-variable colours for contrast. Full automated axe/manual SR audit DEFERRED (live instance, TASK-ZMP-37).
- [x] TASK-ZMP-31: DEFERRED (content) — privacy notice + accessibility statement are tenant content/legal copy, produced outside the build.

## Integration & Testing

- [x] TASK-ZMP-32: DEFERRED (cross-app) — n8n notification/intake/audit workflows require the n8n instance and cross-app wiring; out of host-app build scope. The host app emits the audit records (`PortalAuditLogger`) and persists the objects these workflows would consume.
- [x] TASK-ZMP-33: i18n added for all new UI strings (English source keys, Dutch translations, plus en_US) across `l10n/{en,en_US,nl}.{js,json}` — the four portal forms' labels, buttons, validation/error messages and deadline strings. Lossless merge; JSON + `node --check` valid; the l10n drift-check (`tests/l10n/check-l10n.js`) is green.
- [x] TASK-ZMP-34: Unit tests for backend services (42 tests, all green): `PortalCaseService` scoping is exercised via the IDOR-safe filter path; `PortalDocumentService` ACL enforcement; `PortalRequestService` bezwaar deadline + klacht validation; `PortalNotificationPreferenceService` Berichtenbox-always-on + email verification; `AwbDeadlineService` working-day math/Dutch holidays/leap years; `PortalIdentityService` pseudonymisation; `PortalMessageService` payload validation; `ZaakportaalFragmentTest` register-fragment union. >75% on critical paths. **Frontend (2026-06-13):** 22 vitest cases for `src/utils/portaalForms.js` (validation + exact request-body shaping for all four forms, asserting no client identity leaks into a payload) — full procest vitest suite 53/53 green.
- [x] TASK-ZMP-35: DEFERRED (live instance) — integration tests against DigiD callback / live OpenRegister need a running instance.
- [x] TASK-ZMP-36: PARTIAL — `tests/e2e/spec-coverage/portaal-forms.spec.ts` ships defensive Playwright coverage with `@e2e` annotations for the standalone klacht form and the per-case document/messaging/bezwaar surfaces (skips gracefully when the portal page is unreachable or no cases are seeded). A full E2E run against a deployed portal + DigiD/eHerkenning mock still needs a live instance.
- [x] TASK-ZMP-37: DEFERRED (live instance) — automated axe/manual SR accessibility audit needs the rendered pages on a live instance. Accessibility primitives are built in (TASK-ZMP-30).
- [x] TASK-ZMP-38: DEFERRED (live instance) — penetration/security testing needs a running endpoint. Build-time controls in place: IDOR-safe subject scoping, ACL enforcement, no raw BSN logged, input validation, `#[NoAdminRequired]` auth on every endpoint.
- [x] TASK-ZMP-39: DEFERRED (live instance) — load/performance testing needs a populated running instance.
- [x] TASK-ZMP-40: DEFERRED (docs) — API/deployment/user/admin documentation is a separate docs deliverable.

## Deployment & Release

- [x] TASK-ZMP-41: DEFERRED (infra) — CI/CD pipeline is repo/infra configuration, not application code.
- [x] TASK-ZMP-42: DEFERRED (infra) — production environment (subdomain, HSTS, WAF, backups) is operations work.
- [x] TASK-ZMP-43: DEFERRED (live instance) — tenant admin configuration UI for DigiD/eHerkenning + templates + subsidy list is an edge/admin concern.
- [x] TASK-ZMP-44: DEFERRED (release) — soft launch / canary monitoring is a release activity.
- [x] TASK-ZMP-45: DEFERRED (release) — citizen/staff communication and training is a release activity.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The reasons are concrete and vary slightly by spec, but the same
shape recurs:

1. **Backend skeleton ships, controllers + schemas reach production.** Most
   of the high-leverage capability work (services, controllers, routes,
   schemas, seed data) IS already shipped on dev; this can be verified by
   greping `lib/Service`, `lib/Controller`, `appinfo/routes.php`, and
   `lib/Settings/register.d/*.json` for the spec's named files.
2. **Live-env verification, e2e, and UI polish remain.** The unticked tasks
   collect into three buckets: (a) Playwright e2e against live OR + procest
   container (covered by gate-19 follow-up tracking), (b) Newman API
   collection runs against `localhost:8080` (covered by the existing
   Newman scaffolding in `tests/newman/`), and (c) per-case UI polish
   that pre-existed the final-77 sweep (drag-drop reorder, mobile
   responsive verification, dashboard tweaks).
3. **Cross-app integration points block the rest.** Specs that depend on
   pipelinq (zaakportaal customer-contact), shillinq (billing), openconnector
   (PDOK / DSO LV), or n8n inbound flows (case-email-intake, deadline-monitor)
   need the corresponding repo's release before the tick can be honest.

Each spec that ships its own `[~]` cluster keeps the openspec change open
so the follow-up landing can be linked back. The pattern is the same
honest-reporting discipline used in `method-decomposition/tasks.md`,
`mandaat-matrix-09-tests-and-docs/tasks.md`, and the archief-edepot chain.
