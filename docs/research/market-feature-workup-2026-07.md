# Dossiq Market-Feature Workup — July 2026

Full workup of every feature-bearing finding from the 2026-07 market research (142 Spectr
insights, 8 tracked competitor features, 773+ tenders, 6 competitor deep-dives), cross-checked
against the dossiq implementation audit (40 core capabilities) and the fleet abstraction
inventory (102 features across OpenRegister, nc-vue, Nextcloud, nldesign).

**Why "only 5 features" came out of the research wave:** the research surfaced ~108
feature-level demands. 35 were already shipped in dossiq, ~30 are delivered by the
abstraction layers (OpenRegister / nc-vue / Nextcloud / nldesign / sibling apps), 5 were built
in wave 1, and the remainder are worked out below as wave-2 builds or explicit deferrals.
Per ADR-Leaf-First, anything an abstraction layer provides is consumed, never rebuilt.

**Legend**
- ✅ shipped in dossiq
- 🧩 delivered by abstraction (layer noted — consume, don't rebuild)
- 🏗️ built in wave 1 (2026-07-12/13)
- 🔨 genuine gap — **building in wave 2** (target repo noted)
- 📋 deferred — needs its own dedicated wave (reason noted)
- 🚫 not a product feature (process/market insight)

## 1. Case management core

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| Case CRUD + zaaktype designer | table stakes | ✅ | dossiq `case-types` (CRUD, publish-validation) |
| Zaaktype versioning | open-zaak #2317, admin wish | ✅ | dossiq case-types |
| Zaaktype copy/duplicate | open-zaak #693/#517 — top functional-admin wish | 🔨 | **dossiq W2** — copy + draft-delete |
| Status transition engine | table stakes | ✅ | dossiq thin consumer of OR `x-openregister-lifecycle` |
| Bulk status transitions | high-volume case types (annual permits, subsidy rounds) | 🏗️ | dossiq PR #195 |
| Bulk handler reassignment | personnel change | ✅ | dossiq CaseReassignmentService + BulkReassignModal |
| Werkvoorraad intelligence (priority/deadline sort, workload balance) | "critical for handler productivity — most zaaksystemen weak" | 🔨 | **dossiq W2** — extend My Work |
| Deelzaken (sub-cases) | ZGW model | ✅ | dossiq DeelzaakService |
| Related-case linking | ZGW relevanteAndereZaken | ✅ | dossiq CaseRelationService |
| Generic object relations UI | — | 🧩 | OR EntityRelation + nc-vue CnRelatedObjectsWidget |
| Multi-tenancy (SaaS/shared-service) | small municipalities need shared-service delivery | ✅ | dossiq tenant-* + OR MultiTenancyTrait |
| Task management | table stakes | ✅ | dossiq (OR objects per ADR-022) |
| Delegation / vervanging & waarneming | absence handling | ✅ | dossiq SubstitutionService (+ audit) |
| My Work dashboard | handler productivity | ✅ | dossiq MyWorkCards + KpiAggregationService |
| Confidentiality levels | AVG purpose limitation | ✅ | dossiq case-types (inherited enum) |
| Adaptive case management (CMMN) | tech-recommendation | 📋 | own wave — engine-level design decision |

## 2. Workflow & besluitvorming

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| BPMN 2.0 task lifecycles | municipal process automation | ✅ | dossiq (README-claimed, verified) |
| Zero-coding visual process designer | xxllnc headline feature; low-code = procurement criterion | 🔨 | **dossiq W2** — wire existing V1 editor into settings properly |
| DMN decision tables | permit rule evaluation | 📋 | README: explicit roadmap; engine choice needed |
| Approval chains / parafering | table stakes | ✅ | dossiq via OR approval-workflow leaf |
| Mandaat matrix + escalation | Awb mandaat | ✅ | dossiq Mandaat* services |
| Besluitvorming + DROP/LVBB publication | college besluiten | ✅ | dossiq BesluitvormingPublishHandler |
| Beschikking pipeline (compose→sign→deliver→archive) | 70% time saving on decision letters | ✅ | dossiq + docudesk/openconnector adapters |
| eIDAS-aligned digital signing | LibreSign = native NC signing leaf | 🔨 | **dossiq W2** — LibreSign adapter for besluit/beschikking signing |
| Rule-based automation triggers | — | 🧩 | Nextcloud workflowengine + n8n (openconnector) |
| Event-driven case events (CloudEvents) | process transparency, integration | 🧩 | OR WebhookService (HMAC, retry) |

## 3. Termijnen & compliance (AWB / AVG / Archiefwet)

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| AWB termijnbewaking | non-negotiable; lex silencio risk | ✅ | dossiq TermijnDailyScanService |
| Dwangsom calculation + ingebrekestelling | financial liability | ✅ | dossiq Dwangsom*/Ingebrekestelling services |
| Doorlooptijd dashboards | Woo avg 143 vs 42 days statutory | ✅ | dossiq DoorlooptijdService |
| Milestones | — | ✅ | dossiq MilestoneService |
| AVG verwerkingenlogging | distinct from audit trail | 🧩 | OR ProcessingLogService (dossiq declares catalogue) |
| GDPR DSAR (subject rights) | — | 🧩 | OR DsarService |
| Retention / Archiefwet automation | — | 🧩 | OR RetentionService |
| Complete audit trail w/ before/after (Rekenkamer scrutiny) | audit-finding | 🧩🏗️ | OR hash-chained trail + new cross-app audit query endpoint (OR PR #362) |
| Legal hold / sensitivity labels / data lifecycle | NC Hub 26 Spring Governance | 🧩 | Nextcloud Governance + dossiq legal-hold listener |

## 4. Documents & archiving

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| DMS (storage, versions, trash, sharing) | competitors need separate DMS — NC-native is the moat | 🧩 | Nextcloud Files |
| Zaakdossier compilation + ZIP export | — | ✅ | dossiq ZaakdossierService/DossierCompiler |
| Template doc generation w/ municipal huisstijl | per-municipality branding required | 🧩 | docudesk (templates) + nldesign (42 token sets) |
| PDF/A-3 conversion | MDTO long-term preservation | 🔨 | **docudesk W2** — conversion leaf |
| TMLO/MDTO e-depot transfer | mandatory; most competitors incomplete → differentiator | 🧩 | OR TmloService/EdepotTransferService/SipPackageBuilder |
| NEN-ISO 16175 recordmanagement | xxllnc certified | 🧩🚫 | OR archival covers function; certification = business process |
| Woo redaction / anonymisation | Woo requests | ✅ | dossiq WOORedactionService (LLM-assist: 📋 enhancement) |
| Woo active publication (11 categories, Woo-index) | proactive disclosure duty | 🔨 | **dossiq W2** — publish bridge to opencatalogi (owns DCAT publication) |

## 5. Search, lists & data operations

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| NC unified search over cases | audit-finding gap | 🏗️ | OR ObjectsProvider + searchable flags + deepLinks (PR #192) |
| Faceted search / filter UI | — | 🧩 | OR FacetHandler + nc-vue CnFacetSidebar/CnFilterBar |
| Saved views / saved filters | handler productivity | 🔨 | **nc-vue W2** — UI over OR ViewService (backend exists) |
| Multi-column sort | — | 🔨 | **nc-vue W2** — UI over OR QueryHandler (backend exists) |
| List export CSV/Excel | audit-finding gap | 🏗️ | OR export leaf + nc-vue Export menu (nc-vue PR #197) |
| PDF export of lists/reports | not anywhere in fleet | 🔨 | **openregister W2** — add pdf format to ExportService |
| Bulk import w/ per-row errors | — | 🧩 | OR ImportService |
| Legacy-zaaksysteem migration tooling | migration = 25–50% of procurement cost — wins deals | 📋 | own wave — per-vendor mapping packs on OR ImportService |
| Version history | — | 🧩 | OR semantic versions per save |
| Version diff viewer UI | Rekenkamer before/after scrutiny | 🔨 | **nc-vue W2** — diff component on OR versions |
| Comments / notes on cases | — | 🧩 | NC ICommentsManager + nc-vue CnNotesTab |
| @mentions w/ autocomplete + notification | collaboration table stakes | 🔨 | **nc-vue W2** — CnNotesTab mention autocomplete → NC notifications |
| Scheduled reports (cron + delivery) | controller/management need | 🔨 | **openregister W2** — scheduled export jobs on export leaf |

## 6. Portals & citizen interaction

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| Citizen portal (MijnZaken) | top user wish: status + context + remaining steps | 🧩 | **Portaliq** (dossiq ships backend `/api/portaal/*` + PortalContributionProvider) |
| Supplier portal | — | 🧩 | Portaliq (dossiq schemas + supplier audience) |
| Mobile/offline inspections | only 3 of 43 vendors have mobile inspection apps | 🧩 | nc-vue offline leaf + OR forms/photos + Portaliq inspector audience |
| Real-time status tracking (e-commerce-like) | reduces calls 30% | 🧩 | Portaliq on dossiq status API |
| DigiD / eHerkenning EH3 (Wdo Stelsel Toegang H2 2026) | mandatory for citizen portals | 🧩 | Nextcloud user_oidc/user_saml via Portaliq |
| Regelhulp routing | intake quality | 📋 | Portaliq backlog |
| Wmebv 12 obligations (in force 1-1-2026) | legal | ⚠️ | mostly covered (digital channel, receipt, portal); SMS duty-of-care → §7 |

## 7. Communication channels

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| Case email integration + archival | M365 interop expectation | ✅ | dossiq CaseEmailService |
| Berichtenbox / MijnOverheid | — | ✅ | dossiq BerichtenboxRoutingService |
| SMS channel (NotifyNL) | audit-finding gap; multi-channel = standard expectation | 🔨 | **openconnector W2** — NotifyNL/SMS notification leaf |
| In-app notifications | — | 🧩 | Nextcloud INotificationManager + OR dispatcher |
| KCC: every contact registered | Mozard headline feature | ✅ | dossiq KCC integration + ContactMomentService |

## 8. Integrations & standards

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| ZGW APIs (ZRC/ZTC/DRC/BRC/NRC/AC) | table stakes | ✅ | dossiq, 62 routed endpoints |
| ZGW OpenAPI publication | audit-finding gap; sandbox accelerates integration | 🏗️ | dossiq PR #194 (6 OpenAPI 3.0.3 docs + conformance tests) |
| ZGW v1.6 + next-gen (2026) | standards evolving | 📋 | track VNG; 1.x remains compatible |
| BRP (haal-centraal) | mandatory base register | ✅ | dossiq HaalCentraalBrpAdapter |
| KvK / HR | mandatory | ✅ | dossiq KvkApiAdapter |
| BAG | mandatory; VTH/spatial | 🔨 | **dossiq W2** — haal-centraal BAG adapter (pattern: BRP/KvK) |
| BRK / WOZ | "valuable but not critical for initial launch" | 📋 | defer per research |
| DSO / SWR (Omgevingswet) | mandatory; own connector = strategic (avoid 3rd-party dependency) | 📋 | own wave — large, certification track |
| Open Formulieren intake | no-code forms = most-requested feature; Decos bought Seneca for it | 🔨 | **openconnector W2** — intake bridge onto OR semantic-case-intake handoff |
| iWMO / iJW messages | social domain interoperability | 📋 | own wave (social domain) — openconnector |
| KISS KCC bridge | KISS = reference KCC component | 📋 | openconnector; dossiq has native KCC today |
| FSC (NLX successor, 2025) | Common Ground connectivity | 📋 | openconnector |
| n8n / low-code automation | unique NC-ecosystem advantage | 🧩 | openconnector + n8n-nextcloud |
| GIS / PDOK / BGT maps | essential for VTH | ✅ | dossiq via OR maps leaf + PDOK adapters |
| Open Raadsinformatie feed | — | ✅ | dossiq RaadsinformatieFeedController |
| Omgevingsplan integration | 2029 horizon | 📋 | watch |
| M365/Teams calendar interop | most municipalities on M365 | 🧩 | NC ecosystem (mail/calendar); case email ✅ |

## 9. AI (EU AI Act: transparency binds 2-8-2026)

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| AI classify / extract / summarise / routing / next-step | Joni, AiConnect, Mynte = table stakes | ✅ | dossiq AiService (6 operations) |
| Auditable/explainable AI (every suggestion logged) | "sovereign, explainable AI is the battleground" | 🏗️ | dossiq audit-at-suggestion-time (PR #196) + OR audit query (PR #362) |
| In-product conversational assistant | Decos Joni | 📋 | ask() exists; chat UI own wave — NC Assistant is weak in Dutch (needs tuned model) |
| LLM Woo-anonymisation | 6-municipality NC-native precedent | 📋 | enhancement on WOORedactionService |
| AI case classification accuracy | Signalen 85% | ✅ | dossiq classify + audit trail |

## 10. Security & access

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| RBAC role-based routing | AVG purpose limitation | ✅ | dossiq via OR RBAC ↔ NC groups bridge |
| Field-level permissions | — | 🧩 | OR PropertyRbacHandler |
| Field-level encryption at rest | — | 📋 | OR — security-sensitive, own design review |
| MFA (BIO 2.0) | required | 🧩 | Nextcloud 2FA (TOTP/FIDO2) |
| SSO (SAML/OIDC, municipal AD) | friction + security | 🧩 | Nextcloud user_saml / user_oidc |
| CSP / rate limiting | — | 🧩 | Nextcloud + nldesign self-hosted fonts |
| EU/sovereign hosting | coalition agreement, Rijk investigates NC | ✅ | inherent — self-hosted NC is the wedge |
| BIO / Suwinet / ENSIA certification | market entry | 🚫 | business/certification process, not code |
| NIS2 / Cyberbeveiligingswet (~mid-2026) | municipalities in scope | 🚫 | ops/process; NC hardening guides apply |

## 11. Analytics & reporting

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| KPI dashboards | — | ✅ | dossiq + nc-vue CnDashboard* + OR DashboardService |
| Process mining / bottleneck analysis | 40–60% improvement potential | 📋 | own wave — doorlooptijd data already captured |
| IV3 per-case cost reporting | reduces controller burden quarterly | 🔨 | **dossiq W2** — cost-per-taakveld export |
| Custom-branded reports | per-municipality huisstijl | 🧩 | docudesk templates + nldesign tokens |

## 12. Platform, UX & deployment

| Feature | Evidence | Status | Provider / action |
|---|---|---|---|
| NL Design System / huisstijl | legally anchored (toegankelijkheid, herkenbaarheid) | 🧩 | nldesign (42 municipal token sets, Rijkshuisstijl) |
| Dutch UI language | non-negotiable | ✅ | dossiq nl-locale coverage change |
| WCAG 2.1 AA / EAA (fines to €90k) | enforceable since 28-6-2025 | ⚠️ | ongoing — kanban keyboard-a11y change in flight; systematic audit 📋 |
| Dark mode / theming / i18n | — | 🧩 | Nextcloud CSS vars + nc-vue registerTranslations |
| Offline / PWA framework | — | 🧩 | nc-vue offline integration leaf (extracted from dossiq) |
| Haven-compliant K8s / Docker deployment | procurement cooperatives demand standard deploys | 📋 | infra wave — Helm charts |
| Horizontal scaling (municipal mergers) | — | 🧩 | Nextcloud platform |
| Common Ground (API-first, data at source) | de-facto procurement requirement | ✅ | architecture: OR = data at source, ZGW APIs, components |

## Wave-2 build list (14 features, launched 2026-07-13)

| # | Change | Repo | Base |
|---|---|---|---|
| 1 | zaaktype-copy | dossiq | development |
| 2 | werkvoorraad-intelligent-queue | dossiq | development |
| 3 | workflow-editor-integration | dossiq | development |
| 4 | libresign-besluit-signing | dossiq | development |
| 5 | woo-publication-via-opencatalogi | dossiq | development |
| 6 | iv3-case-cost-reporting | dossiq | development |
| 7 | bag-register-adapter | dossiq | development |
| 8 | saved-views-ui | nextcloud-vue | beta |
| 9 | multi-column-sort-ui | nextcloud-vue | beta |
| 10 | version-diff-viewer | nextcloud-vue | beta |
| 11 | notes-mentions-autocomplete | nextcloud-vue | beta |
| 12 | export-pdf-format | openregister | development |
| 13 | scheduled-report-jobs | openregister | development |
| 14 | notifynl-sms-channel | openconnector | development |

## Explicit deferrals (need their own dedicated wave)

DSO/SWR connector (certification track, months), DMN engine, CMMN adaptive case management,
process mining, iWMO/iJW, FSC, KISS bridge, legacy-migration mapping packs, conversational AI
assistant (Dutch-tuned), field-level encryption (security review), Haven/K8s charts,
systematic WCAG audit, BRK/WOZ adapters (research: defer), Omgevingsplan (2029).
