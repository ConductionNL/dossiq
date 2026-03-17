# Feature Counsel Report: procest

**Date:** 2026-03-16
**Method:** 8-persona feature advisory analysis against OpenSpec specifications
**Personas:** Henk Bakker, Fatima El-Amrani, Sem de Jong, Noor Yilmaz, Annemarie de Vries, Mark Visser, Priya Ganpat, Jan-Willem van der Berg

---

## Executive Summary

Procest is a technically sound case management system with strong CMMN/ZGW foundations and a solid data architecture built on OpenRegister. However, all 8 personas independently identified the same critical gap: **Procest is built entirely for internal case handlers and administrators — not for citizens or business owners who are the actual subjects of the cases**. The legally required Citizen Portal (Mijn Zaken, Wmebv) is planned but completely unspecified. Beyond this central gap, four key themes emerged: (1) missing bulk operations and export features that block real-world usage, (2) critical security and compliance gaps (audit log export, multi-tenancy isolation, ENSIA evidence), (3) absent OpenAPI specification blocking all integrators, and (4) insufficient accessibility enforcement (no concrete WCAG AA targets, no plain-Dutch mandate). The foundation is excellent; the gaps are mostly where internal processing meets the outside world — but several are legally blocking.

---

## Overall Results

| Persona | Missing Features | Improvement Suggestions | Compliance Gaps |
|---------|-----------------|-------------------------|-----------------|
| Henk Bakker | 10 | 10 | 8 |
| Fatima El-Amrani | 8 | 10 | 6 |
| Sem de Jong | 8 | 8 | 7 |
| Noor Yilmaz | 10 | 10 | 9 |
| Annemarie de Vries | 12 | 10 | 10 |
| Mark Visser | 10 | 10 | 6 |
| Priya Ganpat | 10 | 10 | 10 |
| Jan-Willem van der Berg | 10 | 10 | 7 |
| **Total unique features** | **~40 distinct** | **~35 distinct** | **~25 distinct** |

---

## Consensus Features (suggested by 4+ personas)

| # | Feature | Suggested by | Priority | Impact |
|---|---------|-------------|----------|--------|
| 1 | **Citizen Portal (Mijn Zaken)** — visual status tracker for citizens | ALL 8 | MUST | Legal requirement (Wmebv); 0% citizen-facing functionality today |
| 2 | **Bulk Operations** — select → reassign, status-change, delete, export | Mark, Annemarie, Priya, Jan-Willem, Sem | MUST | Current single-record UX blocks real-world municipal workflows |
| 3 | **CSV/Excel/PDF Export** on all list views + audit trail | Mark, Annemarie, Noor, Jan-Willem, Priya | MUST | Required for compliance audits, WOO/ENSIA, reporting |
| 4 | **Email/SMS Notifications** for case status changes | Fatima, Jan-Willem, Mark, Henk, Noor | SHOULD | Citizens and handlers both need proactive updates |
| 5 | **Audit Log Export** (filterable CSV/PDF, admin page) | Noor, Annemarie, Mark, Priya | MUST | BIO2 A.8, NIS 2.5, ENSIA self-evaluation (annual) |
| 6 | **GEMMA-Standard Case Type Templates** (import pack) | Annemarie, Mark, Jan-Willem, Henk | SHOULD | Reduces deployment from 2 days to 2 hours for 342 municipalities |
| 7 | **Plain Dutch (B1 level)** + `publicLabel` on StatusType | Henk, Fatima, Jan-Willem, Mark | MUST | "Besluitvorming" excludes large portions of population |
| 8 | **Published OpenAPI 3.0 specification** | Priya, Annemarie, Mark | MUST | Blocks all third-party integrations and ZGW API adoption |
| 9 | **Multi-tenancy org isolation** — explicit test scenarios + RBAC matrix | Noor, Mark, Annemarie | CRITICAL | Shared Nextcloud instances are standard in municipalities |
| 10 | **Help/Contact on every page** (phone + email, max 2 clicks) | Henk, Fatima, Jan-Willem | HIGH | No support path mentioned anywhere in current specs |
| 11 | **Concrete WCAG AA requirements** (min 18px text, 48×48px buttons, 4.5:1 contrast) | Henk, Fatima, Sem, Annemarie | MUST | Vague "WCAG AA" claims without measurements are not compliance |
| 12 | **publiccode.yml** in repository root | Annemarie, Mark | MUST | Required for GEMMA Softwarecatalogus listing |

---

## Per-Persona Highlights

### Henk Bakker (Elderly Citizen, 78)
- **Can Henk use this?** NO — Procest is an internal handler tool. No citizen interface exists.
- **Top need**: Citizen Portal with large buttons (48×48px min), plain Dutch, phone number visible on every page
- **Key missing feature**: Any citizen-facing component; also: back-navigation on all views, help/support contact everywhere, confirmation messages ("Opgeslagen!")
- **Quote**: *"Ik zie allerlei technische termen en knoppen die ik niet begrijp. Waar kan ik zien hoe het met mijn aanvraag gaat? En als ik er niet uitkom, wie kan ik dan bellen?"*

### Fatima El-Amrani (Low-Literate Migrant, 52)
- **Can Fatima use this?** NO — no citizen-facing interface; handler UI is text-heavy and jargon-laden
- **Top need**: Visual status tracker with icons + colors (not text), mobile-first, 44×44px touch targets
- **Key missing feature**: WhatsApp/SMS notifications; audio/read-aloud for case status; Arabic/Darija language option
- **Quote**: *"Te veel woorden. Ik begrijp niet wat 'Besluitvorming' betekent. Laat me gewoon een vinkje of een kruis zien — klaar of niet klaar."*

### Sem de Jong (Young Digital Native, 22)
- **Can Sem use this?** YES with frustration — handler UI works but feels dated
- **Top need**: Dark mode, URL state persistence, keyboard shortcuts (Cmd+K), toast notifications
- **Key missing feature**: URL-persisted filters/sort; skeleton loading states; undo toasts; performance budgets (LCP, CLS)
- **Quote**: *"Als ik een gefilterde zakenlijst refreshed en de filters verdwijnen, stop ik ermee. Dit is 2026 — URL state is non-negotiable."*

### Noor Yilmaz (Municipal CISO, 36)
- **Can Noor deploy this?** NO — critical security/compliance gaps block procurement
- **Top need**: Exportable audit logs, multi-tenancy isolation proof, session management controls
- **Key missing feature**: ENSIA evidence export; dedicated audit log admin page with filter/export; cross-org isolation test scenarios
- **Quote**: *"ENSIA evaluatie loopt elk jaar van juli tot december. Zonder exporteerbare auditlogs en bewijs van organisatie-isolatie kan ik dit systeem niet goedkeuren voor aanschaf."*

### Annemarie de Vries (VNG Standards Architect, 38)
- **Can Annemarie recommend this?** NO — missing GEMMA alignment, publiccode.yml, Wmebv citizen portal
- **Top need**: GEMMA_ALIGNMENT.md, Common Ground 5-layer statement, formal ZGW OpenAPI specs
- **Key missing feature**: GEMMA component mapping; pre-built GEMMA case type templates; Citizen Portal spec; EUPL license
- **Quote**: *"Het systeem is technisch goed doordacht, maar VNG kan het niet aanbevelen aan 342 gemeenten zonder expliciete GEMMA-mapping, publiccode.yml en een gespecificeerde Mijn Zaken portal — dat is immers wettelijk verplicht."*

### Mark Visser (MKB Software Vendor, 48)
- **Can Mark sell this?** NOT YET — missing bulk ops, templates, and citizen portal block sales
- **Top need**: Bulk case operations (checkbox select → reassign/status-change/export); GEMMA template pack
- **Key missing feature**: Bulk operations; pre-configured Dutch case type templates; SLA tracking; team workload view
- **Quote**: *"De eerste vraag die mijn klant stelt is: 'Kan ik dit exporteren naar Excel?' Ik had geen antwoord. Dit is een dealbreaker."*

### Priya Ganpat (ZZP Developer / Integrator, 34)
- **Can Priya integrate with this?** NOT YET — no OpenAPI spec, no webhooks, no sandbox
- **Top need**: Published OpenAPI 3.0 spec; webhook events; RFC 7807 error format; sandbox environment
- **Key missing feature**: OpenAPI spec; webhook/event system; cursor-based pagination; rate limit docs; idempotency keys
- **Quote**: *"Ik kan geen integratie bouwen zonder machine-leesbare API-documentatie. Ik ben nu broncode aan het lezen om endpoints te ontdekken — dat kost mij 3× zoveel tijd."*

### Jan-Willem van der Berg (Small Business Owner, 55)
- **Can Jan-Willem use this?** NO — zero citizen-facing components
- **Top need**: Citizen portal in plain Dutch; automatic email notifications; contact info visible everywhere
- **Key missing feature**: Any citizen interface; phone number in the UI; plain-Dutch status summaries; pre-filled forms with known business data
- **Quote**: *"Dit systeem is gemaakt voor de gemeente, niet voor ondernemers zoals ik. Waar zie ik wanneer mijn vergunning klaar is? En als ik een vraag heb — wie bel ik dan?"*

---

## Feature Suggestions by Category

### Accessibility & Inclusivity

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Citizen Portal with visual design (icons, colors, minimal text, mobile-first) | Henk, Fatima, Jan-Willem, Mark | MUST | Legal requirement (Wmebv); primary touchpoint for low-literate users |
| 2 | Concrete WCAG AA requirements: min 18px body, 48×48px buttons, 4.5:1 contrast | Henk, Fatima, Sem, Annemarie | MUST | Current "WCAG AA" claims have no measurable targets |
| 3 | B1 plain-Dutch mandate + `publicLabel` field on StatusType | Henk, Fatima, Jan-Willem | MUST | Internal "Besluitvorming" → citizen-facing "Uw aanvraag wordt beoordeeld" |
| 4 | Icon + text label pairs on all status indicators (no color-only signaling) | Henk, Fatima, Sem | MUST | Color-blind and low-vision users need redundant coding |
| 5 | 44×44px minimum touch targets on all interactive elements | Fatima | MUST | WCAG 2.5.5; small-phone users (5-inch screens) need tappable elements |
| 6 | Keyboard alternative MUST (not MAY) for drag-and-drop interactions | Henk, Sem | MUST | WCAG 2.1.1 Keyboard; specs currently say "MAY" |
| 7 | Help/support contact (phone + email) on every page, max 2 clicks | Henk, Fatima, Jan-Willem | MUST | No support contact mentioned anywhere in current specs |
| 8 | Audio / read-aloud support for case status (citizen portal) | Fatima | COULD | Enables WhatsApp voice workflow; high-impact for low-literacy |
| 9 | SMS/WhatsApp notifications for status changes | Fatima, Henk, Jan-Willem | SHOULD | Citizens don't check websites; proactive push required |
| 10 | RTL layout support using CSS logical properties | Fatima | COULD | Future-proofing for Arabic/Darija; prevents later rewrite |
| 11 | `prefers-reduced-motion` CSS support for all animations | Sem | SHOULD | Vestibular disorder accessibility |
| 12 | Session timeout warning with extension option | Henk | SHOULD | WCAG 2.2.1 Timing Adjustable |

### Security & Compliance

| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | Audit log export (CSV/PDF, filterable by date/user/action/entity) | Noor, Annemarie, Priya | MUST | BIO2 A.8, NIS Directive 2.5, ENSIA |
| 2 | ENSIA evidence export (dated summary: case counts, access changes, role distributions) | Noor, Annemarie | MUST | ENSIA self-evaluation (annual Jul–Dec) |
| 3 | Multi-tenancy org isolation — explicit test scenarios + RBAC matrix per tenant | Noor, Mark, Annemarie | CRITICAL | AVG Art. 32, NIS Directive 2.14 |
| 4 | Session management: configurable idle timeout (default 30 min) + active sessions page | Noor | SHOULD | ISO 27002:2022 A.6.2.3, BIO2 A.10.2 |
| 5 | Re-authentication for high-risk operations (status→final, result recording) | Noor | SHOULD | ISO 27002 best practice |
| 6 | Admin activity in audit trail (case type config changes with before/after snapshots) | Noor, Annemarie | MUST | Change management, BIO2 |
| 7 | PII minimization in lists: show initials, full names only on detail views | Noor | SHOULD | AVG data minimization principle |
| 8 | Confidentiality change audit with approval trail | Noor, Annemarie | SHOULD | AVG Art. 32 |
| 9 | GDPR data subject rights: personal data export + deletion/redaction | Mark, Annemarie | MEDIUM | AVG Art. 17, 20 |
| 10 | Encryption at rest documentation (Nextcloud-level vs. Procest-level) | Noor | SHOULD | AVG Art. 32, BIO2 A.8.2.3 |
| 11 | Segregation of duties — prevent same user from being handler + decision maker | Noor | SHOULD | BIO2 A.7.1.3 |
| 12 | Security.md: data classification, encryption, vulnerability disclosure | Annemarie | SHOULD | Common Ground security requirements |

### API & Developer Experience

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Published OpenAPI 3.0 specification at well-known URL | Priya, Annemarie, Mark | MUST | Blocks all third-party integrations; schema defs already exist |
| 2 | Webhook/event notifications (case status changed, deadline, task completed) | Priya, Noor, Mark | MUST | Polling doesn't scale; citizen portals need real-time events |
| 3 | RFC 7807 Problem Details error format (type, title, status, detail, instance) | Priya, Annemarie | SHOULD | NLGov API Design Rules mandate; enables programmatic error handling |
| 4 | Rate limiting headers + documentation | Priya | SHOULD | Required for production-grade integrations |
| 5 | Sandbox / test environment with demo data | Priya, Mark | MUST | Municipalities won't test against production data |
| 6 | Cursor-based pagination option (not just offset/limit) | Priya | SHOULD | Large datasets; consistent iteration under concurrent data changes |
| 7 | Bulk API endpoints (batch update/reassign) | Priya, Mark | SHOULD | Prevents N+1 API call patterns from client code |
| 8 | Eager-loading query parameter (`?_embed=caseType,status`) | Priya | SHOULD | Reduces N+1 queries; improves list view performance significantly |
| 9 | Concurrency control (ETags + If-Match headers) | Priya | SHOULD | Prevents lost-update bugs in concurrent editing |
| 10 | API versioning strategy + breaking change policy | Priya, Annemarie | SHOULD | Without this, integrations break silently on updates |
| 11 | Idempotency keys (via `Idempotency-Key` header) | Priya | SHOULD | Prevents duplicate cases on client retry |
| 12 | ZGW API formal OpenAPI specs for active changes (zgw-*-api) | Annemarie, Priya | MUST | Active changes in progress; need complete formal specs before merge |

### UX & Performance

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | URL state persistence for all filters/sort/pagination | Sem, Mark, Priya | MUST | Share filtered views; bookmarks; browser back/forward |
| 2 | Dark mode support (CSS variables + `prefers-color-scheme`) | Sem | SHOULD | Government handlers work long shifts; table-stakes in 2026 |
| 3 | Keyboard shortcuts: Cmd+K command palette, Escape closes modals | Sem | SHOULD | Power user productivity; Escape also required by WCAG 2.1.2 |
| 4 | Skeleton loading states — defined anatomy per card type | Sem | SHOULD | No blank screens during data fetch |
| 5 | Toast notifications (bottom-right, auto-dismiss, undo action) | Sem | SHOULD | Replace blocking modal dialogs for save/delete confirmations |
| 6 | Undo (5-second window) for destructive actions | Sem | SHOULD | Standard 2026 web UX pattern |
| 7 | Performance budget: LCP <1.2s, CLS <0.1, dashboard load <2s | Sem | SHOULD | Core Web Vitals; applicable to government network conditions |
| 8 | Copy-to-clipboard for case IDs, task URLs | Sem | COULD | Quick wins for power users |
| 9 | Case health score / visual indicator (green/yellow/red) | Mark, Sem | SHOULD | At-a-glance risk identification in case lists |
| 10 | Team workload view (all handlers' queues, not just "My Work") | Mark, Noor | SHOULD | Team leads need allocation visibility |
| 11 | Upcoming deadlines panel (30-day forecast, team-wide) | Mark, Annemarie | SHOULD | Proactive workload planning prevents overdue spikes |
| 12 | Visible focus indicators (2px outline) on all interactive elements | Sem | MUST | WCAG AA keyboard accessibility |

### Standards & Interoperability

| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | GEMMA_ALIGNMENT.md — explicit component mapping | Annemarie, Mark | MUST | GEMMA Softwarecatalogus prerequisite |
| 2 | publiccode.yml in repository root | Annemarie | MUST | Standard for Public Code; GEMMA Softwarecatalogus |
| 3 | Common Ground 5-layer architecture statement | Annemarie | MUST | CG adoption requirement; currently implicit |
| 4 | EUPL-1.2 license (or AGPL compatibility statement) | Annemarie | SHOULD | VNG preferred license; AGPL creates procurement friction |
| 5 | Deployment guide for municipalities (Docker, HA, multi-tenant scenarios) | Annemarie, Mark | SHOULD | 342 municipalities need operational documentation |
| 6 | NLGov API Design Rules v2 compliance declaration | Annemarie, Priya | MUST | Required for Dutch government APIs |
| 7 | WCAG AA conformance matrix with audit schedule | Annemarie | SHOULD | Validate "WCAG AA" claims with real evidence |
| 8 | i18n scope: date formats (DD-MM-YYYY), time zones, legal term glossary | Annemarie, Henk | SHOULD | Consistency across municipalities |

### Business & Workflow

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Bulk operations: case select → reassign / status-change / delete / export | Mark, Priya, Annemarie, Jan-Willem, Sem | MUST | #1 handler friction; 60% time saving estimate |
| 2 | GEMMA-standard case type templates (Omgevingsvergunning, Subsidieaanvraag, Klacht) | Annemarie, Mark, Jan-Willem, Henk | SHOULD | Import pack with statuses, deadlines, roles, docs, retention rules |
| 3 | SLA / servicenorm tracking per case type (% within target) | Mark, Annemarie | SHOULD | Primary municipal governance KPI; data exists, not surfaced |
| 4 | Automatic handler notification on case status change | Mark | SHOULD | Currently only initiators are notified; handlers miss reassignments |
| 5 | Workload balancing suggestion on task assignment | Annemarie, Mark | SHOULD | Shows handler's current load when assigning |
| 6 | Extension reason mandatory + audit logged | Mark, Annemarie | SHOULD | Legal audits require documented reasons for deadline extensions |
| 7 | Pre-publication checklist for case types | Annemarie, Mark | SHOULD | Guards against incomplete configuration in production |
| 8 | Case type expiry warning (admin alert 30 days before `validUntil`) | Mark | COULD | Prevents stale case types silently affecting new cases |
| 9 | Business-day deadline calculations (`P40BD` vs `P56D`) | Priya, Annemarie | SHOULD | ZGW deadlines are often in working days |
| 10 | Status transition flowchart visible in UI | Mark, Henk | SHOULD | Handlers don't know which statuses they can jump to |
| 11 | Pre-filled forms with known contact/business data | Jan-Willem | SHOULD | Reduces re-entry friction |
| 12 | Save-draft / resume incomplete applications | Jan-Willem | COULD | Businesses need time to gather required documents |

---

## Recommendations

### CRITICAL (fix immediately — blocks deployment)

1. **Spec and implement the Citizen Portal (Mijn Zaken)** — Wmebv legally requires this. All 8 personas identified it. Move from "Planned" to active specification immediately. The spec must address: simplified visual UI in B1 Dutch, status timeline with `publicLabel`, document download, contact handler, email/SMS notifications, authentication path, and Pipelinq integration. *(Affects: ALL 8)*

2. **Define and test multi-tenancy org isolation** — Add explicit test scenarios proving Gemeente A users cannot see Gemeente B cases. Document RBAC matrix per role. Without this, the product cannot be deployed to municipalities sharing Nextcloud infrastructure. *(Affects: Noor, Mark, Annemarie)*

3. **Implement audit log export with admin UI** — Dedicated Audit Logs page with date/user/action filtering and CSV/PDF export. Required for ENSIA self-evaluation (annual July–December). Without this, no municipality can pass BIO2/ENSIA compliance review. *(Affects: Noor, Annemarie)*

### HIGH (fix before next release)

4. **Add bulk operations** to case list (checkbox select → reassign / status-change / delete / export). Single most impactful efficiency improvement for handlers with 20+ cases. *(Affects: Mark, Priya, Annemarie, Jan-Willem, Sem)*

5. **Publish OpenAPI 3.0 specification** at a well-known URL. Internal schema definitions already exist — export and publish them. Without this, all third-party integrations (Pipelinq, ZGW consumers, citizen portals) are blocked. *(Affects: Priya, Annemarie, Mark)*

6. **Add publiccode.yml** and create GEMMA_ALIGNMENT.md. Prerequisite for GEMMA Softwarecatalogus listing. Without this, VNG cannot recommend the product to 342 municipalities. *(Affects: Annemarie)*

7. **Enforce concrete WCAG AA requirements** throughout specs: min 18px body text, 48×48px touch targets, 4.5:1 contrast, keyboard MUST alternatives for drag-and-drop, visible focus rings, icon+text pairs (no color-only). *(Affects: Henk, Fatima, Sem, Annemarie)*

8. **Add Help/Contact component to every page** — Phone number and email visible within 2 clicks from any page. Currently absent from all specs. *(Affects: Henk, Fatima, Jan-Willem)*

9. **Create and ship GEMMA case type template pack** — Omgevingsvergunning, Subsidieaanvraag, Klacht, Bezwaarschrift — with correct statuses, deadlines, roles, docs, and retention rules. One-click admin import. Cuts deployment time from days to hours. *(Affects: Annemarie, Mark, Jan-Willem)*

10. **Add email/SMS notifications** for case status changes — automatic, in B1 Dutch, explaining what happened and what action is needed. *(Affects: Fatima, Jan-Willem, Mark, Henk, Noor)*

### MEDIUM (improve when possible)

11. **Implement URL state persistence** — All filters, sort order, and pagination in URL query parameters. Required for sharing views and browser navigation. *(Affects: Sem, Mark, Priya)*

12. **Add RFC 7807 error format** for all API responses. Define error codes (CASE_TYPE_NOT_PUBLISHED, MISSING_REQUIRED_PROPERTIES, etc.). *(Affects: Priya, Annemarie)*

13. **Add dark mode** using CSS variables + `prefers-color-scheme`. Government handlers work long shifts; this is table-stakes in 2026. *(Affects: Sem)*

14. **Add skeleton loading states** with defined component anatomy per card type. No blank screens during data fetch. *(Affects: Sem)*

15. **Add session management controls** to admin settings: configurable idle timeout, active sessions page, forced logout capability. *(Affects: Noor)*

16. **Enforce plain Dutch (B1 level) mandate** — All user-facing text must pass readability standards. Remove ISO 8601 duration strings from UI ("P56D" → "56 dagen / 8 weken"). *(Affects: Henk, Fatima, Jan-Willem, Mark)*

17. **Add team workload view** (all handlers' queues, not just "My Work") for coordinators and team leads. *(Affects: Mark, Annemarie)*

18. **Add webhook/event system** spec (event types, HMAC-SHA256 signature, retry, dead-letter queue). Design now even if implementation is V2. *(Affects: Priya, Noor, Mark)*

19. **Spec data retention enforcement** — When and how are cases automatically archived/destroyed? Grace period? TMLO export? *(Affects: Annemarie, Noor, Mark)*

20. **Add status transition flowchart** visible in admin (case type setup) and handler UI. *(Affects: Mark, Henk)*

---

## Potential OpenSpec Changes

These features could be turned into OpenSpec changes using `/opsx:new`:

| Change Name | Description | Related Personas | Complexity |
|-------------|-------------|-----------------|------------|
| `mijn-zaken-citizen-portal` | Citizen-facing case status portal: visual timeline, `publicLabel`, document download, B1 Dutch, mobile-first, email/SMS, authentication path | ALL 8 | XL |
| `bulk-operations` | Bulk select in case/task lists → reassign, status-change, delete, export (CSV/Excel) | Mark, Priya, Annemarie, Sem, Jan-Willem | M |
| `audit-log-export` | Admin audit log page with date/user/action filter and CSV/PDF export; ENSIA summary export | Noor, Annemarie, Priya | M |
| `openapi-publication` | Export internal schema to OpenAPI 3.0, publish at well-known URL, commit to versioning and NLGov compliance | Priya, Annemarie, Mark | M |
| `gemma-alignment-docs` | GEMMA_ALIGNMENT.md, ARCHITECTURE_5LAYER.md, publiccode.yml, EUPL license, deployment guide | Annemarie, Mark | S |
| `gemma-case-templates` | Import pack: Omgevingsvergunning, Subsidieaanvraag, Klacht, Bezwaarschrift — with statuses, deadlines, roles, docs, retention | Annemarie, Mark, Jan-Willem, Henk | L |
| `accessibility-enforcement` | Measurable WCAG AA requirements in all specs; Help/Contact component; B1 Dutch mandate; `publicLabel` on StatusType | Henk, Fatima, Sem, Annemarie | M |
| `multi-tenancy-isolation` | Cross-org isolation test scenarios; RBAC matrix per role; org-scoped API queries | Noor, Mark, Annemarie | L |
| `notification-system` | Email/SMS for status changes, deadline alerts, task assignments; B1 Dutch default templates; notification preferences | Fatima, Jan-Willem, Mark, Henk, Noor | M |
| `url-state-persistence` | All list view filters/sort/pagination persisted in URL query parameters | Sem, Mark, Priya | S |
| `dark-mode-ui` | CSS custom properties for all colors; `prefers-color-scheme` dark mode; `prefers-reduced-motion` | Sem | S |
| `session-management` | Configurable idle timeout, active sessions page, re-auth for high-risk operations, forced logout | Noor | S |
| `webhook-events` | Webhook spec (event types, HMAC-SHA256 signature, retry, dead-letter queue) | Priya, Noor, Mark | L |
| `plain-dutch-standards` | B1 readability mandate, Dutch date formats (DD-MM-YYYY), `publicLabel` field, legal term glossary | Henk, Fatima, Jan-Willem | S |
| `team-workload-view` | Team-level case/task queue for coordinators; workload balancing suggestion on task assignment | Mark, Annemarie | M |
| `retention-workflow` | Retention expiry detection, destruction confirmation, TMLO-format e-depot export | Annemarie, Noor | L |
