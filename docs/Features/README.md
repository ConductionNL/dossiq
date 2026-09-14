# Dossiq: Feature Overview

Dossiq is a Nextcloud case management app (zaaksysteem) for Dutch municipalities, covering general case handling (zaakgericht werken), VTH permits/supervision/enforcement, objection and appeal workflows, B&W decision-making, and workflow automation. All data is stored in OpenRegister: Dossiq owns no database tables.

## Standards Compliance

| Standard | Reference | Status |
|----------|-----------|--------|
| GEMMA Generiek zaakafhandelcomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-f2dfbd0b-9d36-405c-bdbe-827f3296de29) | Implemented |
| GEMMA Zaakregistratiecomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-a97b6545-d5a7-485d-9b13-3ce22db5b9cf) | Implemented |
| GEMMA Zaaktypecataloguscomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-3ef9cdd9-631c-4d3e-88c3-f756423d6314) | Implemented |
| GEMMA Vergunning- Toezicht- Handhavingcomponent (VTH) | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-ca98dd6d-1c0b-43dc-a26e-61ebd1cd810d) | Partial |
| GEMMA VTH Fysieke Leefomgeving | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-0777c4b6-e7c5-4d42-9fe8-9b98e6bca8a6) | Partial |
| GEMMA Bezwaar- en beroepcomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-ec221e15-9b3c-411b-b2f0-c4527d59f25f) | Implemented |
| GEMMA Bestuurlijk activiteiten bewakingcomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-78153895-50be-4f02-aedb-083406347952) | Partial |
| GEMMA Mobiel-toezicht-en-handhavingcomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-f6140c23-112b-4859-a6da-ca96c89898a2) | Planned |
| ZGW Zaken API (ZRC) | [zaakgerichtwerken.nl](https://zaakgerichtwerken.nl) | Implemented |
| ZGW Catalogi API (ZTC) | [zaakgerichtwerken.nl](https://zaakgerichtwerken.nl) | Implemented |
| ZGW Documenten API (DRC) | [zaakgerichtwerken.nl](https://zaakgerichtwerken.nl) | Implemented |
| ZGW Besluiten API (BRC) | [zaakgerichtwerken.nl](https://zaakgerichtwerken.nl) | Implemented |
| ZGW Autorisaties API (AC) | [zaakgerichtwerken.nl](https://zaakgerichtwerken.nl) | Implemented |
| ZGW Notificaties API (NRC) | [zaakgerichtwerken.nl](https://zaakgerichtwerken.nl) | Implemented |
| TEC BPM RFP Template: Process Modeling | Sections 1.1–1.11 | Partial |
| TEC BPM RFP Template: Security Management | Sections 2.1–2.5 | Partial |
| TEC BPM RFP Template: Workflow Portal | Sections 5.1–5.6 | Partial |
| TEC BPM RFP Template: Monitoring & Management | Sections 6.1–6.6 | Partial |
| CMMN 1.1 (OMG) | Case Plan Model, HumanTask, Milestone | Partial |
| Forum Standaardisatie: REST-API Design Rules | [forumstandaardisatie.nl](https://forumstandaardisatie.nl/open-standaarden/rest-api-design-rules) | Implemented |
| Forum Standaardisatie: NL GOV CloudEvents | [forumstandaardisatie.nl](https://forumstandaardisatie.nl/open-standaarden/nl-gov-cloudevents) | Planned |
| Awb (Algemene wet bestuursrecht) | Processing deadlines, bezwaar/beroep | Implemented |
| Woo (Wet open overheid) | 4-week response mandate, redaction | Planned |
| DSO Omgevingsloket | VTH permit intake integration | Planned |
| LHS (Landelijke Handhavingsstrategie) | 4×4 enforcement response matrix | Implemented |
| StUF-ZKN / StUF-BG | Legacy SOAP/XML exchange | Planned |

## Features

| Feature | Summary | Standards | Status | Docs |
|---------|---------|-----------|--------|------|
| Case Management | Create, track, and close cases with configurable types, statuses, and deadlines | GEMMA Zaakafhandel, ZGW ZRC, CMMN 1.1 | Implemented | [case-management.md](case-management.md) |
| Case Types | ZGW-compatible case type catalogue with status types, role types, and property definitions | GEMMA ZTC, ZGW Catalogi API | Implemented | [case-types.md](case-types.md) |
| Zaaktype Configuratie | Field mapping between Dossiq's internal model and Dutch ZGW resource types | ZGW ZRC/ZTC/BRC/DRC | Implemented | [zaaktype-configuratie.md](zaaktype-configuratie.md) |
| Task Management | Task work items linked to cases, with assignees, due dates, and status lifecycle | TEC BPM 5.1, CMMN HumanTask | Implemented | [task-management.md](task-management.md) |
| Roles & Decisions | Case participant role assignment (behandelaar, initiator, adviseur) and formal decision recording | GEMMA Zaakafhandel, ZGW BRC, ZGW ZRC Rol | Partial | [roles-decisions.md](roles-decisions.md) |
| Dashboard | Landing page with KPI cards (open, overdue, completed, my tasks), status chart, and work list | TEC BPM 5.3, 6.3 | Implemented | [dashboard.md](dashboard.md) |
| My Work | Personal work queue showing cases and tasks assigned to the current user | TEC BPM 5.1, 5.5 | Implemented | [my-work.md](my-work.md) |
| Werkvoorraad | Team-level queue of unassigned cases available for claiming | TEC BPM 5.1, GEMMA Zaakafhandel | Planned | [werkvoorraad.md](werkvoorraad.md) |
| Administration | Nextcloud admin panel for schema configuration, ZGW mapping, and seed data import | Nextcloud OCP | Implemented | [administration.md](administration.md) |
| Admin Settings | Configuration page for register/schema UUID mappings and version information | Nextcloud OCP | Implemented | [admin-settings.md](admin-settings.md) |
| OpenRegister Integration | All data stored as OpenRegister objects: Dossiq owns no database tables | OpenRegister API | Implemented | [openregister-integration.md](openregister-integration.md) |
| Base Register Seed Data | Pre-configured case types (Bezwaar, Vergunning, Melding, VTH) imported on install | GEMMA | Implemented | [base-register-seed-data.md](base-register-seed-data.md) |
| Workflow Engine | Zero-code visual workflow builder with status nodes, guards, and automatic actions | TEC BPM 1.1–1.6, BPMN 2.0 | Implemented | [workflow-engine-enhancement.md](workflow-engine-enhancement.md) |
| VTH Module | Permits, supervision, and enforcement case types and domain schemas | GEMMA VTH, DSO Omgevingsloket | Partial | [vth-module.md](vth-module.md) |
| VTH Workflow Configuration | Inspection checklists, enforcement wizard, LHS matrix, and VTH seed data | GEMMA VTH Fysieke Leefomgeving, LHS | Implemented | [vth-workflow-configuration.md](vth-workflow-configuration.md) |
| Bezwaar/Beroep Workflow | AWB-compliant objection and appeal case types with pre-seeded workflows and timelines | GEMMA Bezwaar/Beroep, Awb Hoofdstuk 7 | Implemented | [bezwaar-beroep-workflow.md](bezwaar-beroep-workflow.md) |
| B&W Besluitvorming Workflow | Formal B&W decision-making with parafering (sign-off) chain and notifications | GEMMA Bestuurlijk activiteiten | Implemented | [besluitvorming-workflow.md](besluitvorming-workflow.md) |
| B&W Parafering | Digital approval routing through mandate-verified sign-off chains | GEMMA Bestuurlijk activiteiten | Planned | [bw-parafering.md](bw-parafering.md) |
| Sub-case Support (Deelzaken) | Hierarchical cases with parent-child linking, roll-up indicators, and ZGW hoofdzaak/deelzaken mapping | ZGW ZRC-013, CMMN | Implemented | [deelzaak-support.md](deelzaak-support.md) |
| Doorlooptijd Dashboard | SLA adherence analytics with processing time distribution, compliance rate, trends, and at-risk cases | Awb, Woo, TEC BPM 6.3–6.4 | Implemented | [doorlooptijd-dashboard.md](doorlooptijd-dashboard.md) |
| Signalering Widgets | Six Nextcloud Dashboard widgets for deadline alerts, overdue cases, stalled cases, and task reminders | Nextcloud Dashboard API | Implemented | [signalering-widgets.md](signalering-widgets.md) |
| Case Dashboard View | Comprehensive case detail page with status timeline, panels, tasks, documents, and audit trail | CMMN, ZGW | Implemented | [case-dashboard-view.md](case-dashboard-view.md) |
| GIS Integration | Map view for cases, location picker, PDOK/WMS/WFS overlay, and secure GIS proxy | BAG, BRK, PDOK | Implemented | [gis-integration.md](gis-integration.md) |
| Milestone Tracking | Key progress checkpoints per case with target dates, overdue alerts, and visual timeline | CMMN Milestone, TEC BPM 6.3 | Planned | [milestone-tracking.md](milestone-tracking.md) |
| ZGW APIs | Full ZGW API suite: ZRC, ZTC, DRC, BRC, AC, NRC: VNG Newman test suite compliance | ZGW 1.x, VNG | Implemented | [zgw-apis.md](zgw-apis.md) |
| Zaak Intake Flow | Structured intake form with case type selection, auto-numbering, and deadline calculation | ZGW ZRC, DSO | Planned | [zaak-intake-flow.md](zaak-intake-flow.md) |
| Complaint Management | AWB-compliant klachtenprocedure with hearings, deadlines, and ombudsman escalation | Awb Hoofdstuk 9, GEMMA | Planned | [complaint-management.md](complaint-management.md) |
| Consultation Management | Public participation (inspraak) with response collection and nota van beantwoording | Omgevingswet, Awb | Planned | [consultation-management.md](consultation-management.md) |
| WOO Case Type | Open Government Act disclosure requests with redaction, zienswijze, and publication | Woo, Forum Standaardisatie | Planned | [woo-case-type.md](woo-case-type.md) |
| Legesberekening | Automated municipal fee calculation based on the legesverordening | Legesverordening | Planned | [legesberekening.md](legesberekening.md) |
| Case Email Integration | Link email communication to cases and create cases from incoming email | ZGW, Nextcloud Mail | Planned | [case-email-integration.md](case-email-integration.md) |
| Appointment Scheduling | Schedule hearings, consultations, and inspections linked to cases | Nextcloud Calendar | Planned | [appointment-scheduling.md](appointment-scheduling.md) |
| Case Sharing & Collaboration | Cross-department and federated case sharing with role-based access | Nextcloud Federation | Planned | [case-sharing-collaboration.md](case-sharing-collaboration.md) |
| Case Definition Portability | Export and import case type definitions between Dossiq instances | OpenCatalogi | Planned | [case-definition-portability.md](case-definition-portability.md) |
| MijnOverheid Integration | Publish case status and notifications to the national citizen portal | Logius Berichtenbox, DigiD | Planned | [mijn-overheid-integration.md](mijn-overheid-integration.md) |
| Mobiel Inspectie | Mobile-optimized inspection interface with checklists, photo capture, GPS, and offline sync | GEMMA Mobiel toezicht | Planned | [mobiel-inspectie.md](mobiel-inspectie.md) |
| StUF Support | Legacy StUF-ZKN/BG SOAP/XML bridge for connecting to older government systems | StUF-ZKN, StUF-BG | Planned | [stuf-support.md](stuf-support.md) |
| AI-Assisted Processing | Document summarization, auto-classification, anonymization (Presidio), and deadline risk prediction | NL GOV, Nextcloud AI | Planned | [ai-assisted-processing.md](ai-assisted-processing.md) |
| Register i18n | Full Dutch + English translation using Nextcloud gettext/l10n infrastructure | Forum Standaardisatie i18n | Partial | [register-i18n.md](register-i18n.md) |
| Multi-Tenant SaaS | Tenant isolation, per-tenant configuration, and NL Design System theming per tenant | Nextcloud Groups | Planned | [multi-tenant-saas.md](multi-tenant-saas.md) |
| Prometheus Metrics | `/metrics` endpoint for Prometheus scraping with SLA compliance and queue depth metrics | OpenMetrics | Planned | [prometheus-metrics.md](prometheus-metrics.md) |
| Start Case Widget | Dashboard widget for starting new cases directly from the Nextcloud dashboard | Nextcloud Dashboard API | Implemented | [start-case-widget.md](start-case-widget.md) |
| App Scaffold | PHP/Vue app foundation, OpenRegister wiring, Pinia stores, and build system | Nextcloud OCP | Implemented | [app-scaffold.md](app-scaffold.md) |

## How each area measures against the competition

One line per area of the parity matrix, from [competitor parity, September 2026](../research/competitor-parity-2026-09.md), as of ledger v10 and gap register v4 (2026-09-14). The dossiq count is its `yes` answers over the area's rows in the 206-row matrix as read on 2026-09-07 with the 2026-09-08 corrections (`procest/_round2/compare/M1-functionality.md` in `ConductionNL/market-intelligence`); the [re-read of 2026-09-13](../research/competitor-gap-re-read-2026-09-13.md) closed twelve rows after that count, and the lines say which. Row ids are the matrix's; a `Q` prefix marks a pending proposal. Competitor counts are the driven columns in the tallies in `procest/_round4/compare/`; Helpdesk is Frappe Helpdesk 1.30.1 and RT is Request Tracker 5.0.10, both added by batch 9. Batch 11's two columns, Gitea 23 and Taiga 32, lead no area over dossiq. Batch 12's do: Huly 60 is above dossiq in case core, tasks, documents and search, and Tuleap CE 65 in reporting, configuration, and access and privacy.

| area | dossiq | rows dossiq leads on | rows dossiq does not lead on |
|---|---|---|---|
| 1 Intake | 4 of 13 | None outright; xxllnc Zaken 9, OpenCase, OTOBO, Odoo and Znuny 7, Helpdesk 6, RT 5. | 1.3 create a case from a contact record, 1.8 planned case series (both dossiq changes); 1.4 the document intake queue (filinq). |
| 2 Case core | 9 of 22 | 2.7 status vocabulary with colour and order, 2.12 reopen a closed case, 2.15 custom objects on the case, all closed by the re-read. | 2.4 one-click claim, which all three Dutch rivals give a handler; 2.3 the case type version chain; 2.13 rebinding a running case; 2.20 live updates on the case page. xxllnc 16, GZAC 13, Odoo and Helpdesk 11, Huly 10. |
| 3 Tasks and phases | 6 of 19 | 3.3 checklist per status and 3.10 flows startable from the case, closed by the re-read; 3.23 the condition of a rule as data. | 3.8 task defaults to the case handler; 3.22 a stage that refuses work past its capacity; Q3.21 a dependent term that follows its predecessor. GZAC 14, xxllnc 11, OTOBO 10, Huly 9. |
| 4 Documents | 7 of 23 | Level with Deck, whose seven are the host's, and above every other non-Dutch column but one. | xxllnc 18, OpenCase 13, Huly 8 from a document manager of its own. The six Files rows Deck passes on the same host; 5.12 document correspondents; 4.19 the scan verdict on the row. |
| 5 Parties and contacts | 5 of 13 | 5.3 BRP and KvK lookup, 5.5 secrecy indication, 5.10 a BAG object as a party, all statutory; 5.1 parties with roles, closed by the re-read. | 5.8 a gemachtigde on every case type; 5.2 the contact registry; 5.11 registry subscriptions. xxllnc 11, OpenCase 7. |
| 6 Communication | 4 of 14 | None outright. Round 2 measured that no competitor composes another product into the case page, which dossiq does with mail, appointments, decisions and hours as tabs. | 6.15 internal versus public per timeline entry, which dossiq alone fails; 6.18 reply threading by mail headers; 6.19 citizen status labels; 6.20 signed outbound webhooks. xxllnc 10, Znuny, Odoo and Helpdesk 7. |
| 7 Decisions | 6 of 7 | The whole area: 7.1 to 7.6, where twenty-four of the twenty-six non-Dutch columns score 0 of 7, Tuleap CE and Huly score 1 each on the outcome list, and xxllnc 3. | 7.7 the result driving archival nomination and retention (openregister). |
| 8 Deadlines | 8 of 10 | 8.1, 8.2, 8.3, 8.5, 8.6, 8.8, 8.9 and 8.10; xxllnc 7, the OTRS forks and Odoo 5. Easter, which nothing else in twenty-six non-Dutch columns computes. | 8.11 the Atw roll, 8.12 an administered calendar, 8.17 recompute when the calendar changes, Q8.16 counting mode per term: 30.4% of stored terms land on a non-working day. 8.4 reminders as tasks; 8.7 retention on the case. |
| 9 Search | 4 of 12 | 9.10 search over custom objects, closed by the re-read. | 9.11 task search fields; 9.16 a saved search as a place of its own; 9.1 and 9.12 unified search over the register. xxllnc 10, OTOBO, Odoo and RT 8, Helpdesk, Huly and Redmine 7, Tuleap 6. RT's 8 is TicketSQL, a query language with saved searches, charts and dashboards. |
| 10 Reporting | 5 of 10 | 10.2, 10.3, 10.4, 10.6 and 10.9: widgets, export, process analytics, a reporting API and the audit timeline. | 10.1 configurable KPI dashboards; 10.7 an export area with expiry; Q10.14 refusals that carry a status; Q10.15 dwell time on the working calendar. xxllnc, Znuny, OTOBO, iTop and Tuleap 7, Helpdesk 6. |
| 11 Configuration | 9 of 24 | 11.21 mother and child case types and 11.22 AVG register fields, closed by the re-read; 11.27 the capability is in the edition you can deploy. | 11.23 attribute catalogue folders; 11.17 object type management; Q11.31 schemas with no surface; 11.6 to 11.8 page layout per case type (buildiq). GZAC 18, xxllnc 13, Znuny, Odoo, RT and Tuleap 11, Helpdesk 10. |
| 12 Integrations | 11 of 23 | 12.1 ZGW APIs, 12.2 Notificaties API, 12.4 StUF, 12.5 DSO, 12.6 the national registries, 12.16 PDOK, all statutory; 12.17 the integrations page, closed by the re-read. | 12.3 the Objecten API (openregister); 12.8 DigiD and eHerkenning beyond the simulator (integriq); 12.9 Berichtenbox; 12.11 the document generation vendor adapter; Q12.25 declared prerequisites. |
| 13 Access and privacy | 6 of 16 | 13.11 the AVG register per case type, closed by the re-read. | 13.18 watchers on a case, where dossiq is the only `no` in the ledger; 13.17 substituted work in My work; 13.23 a right on a parent case reaching its sub-cases; 13.8 field rules by state. xxllnc 11, OpenCase 7, Helpdesk and Tuleap 7, the highest of the twenty-two driven non-Dutch columns. |

The rows in the last column are the gap register's, one owner each. 150 of the 161 gaps now name the OpenSpec change that carries them, in the repository that owns it, so a row in that column can be followed to a change directory you can open. The [result page](../research/competitor-parity-2026-09.md#the-gap-register-counted-by-owner) counts them by owner, and the [OpenSpec umbrella](https://github.com/ConductionNL/dossiq/pull/2643) opens the dossiq changes.

A second sweep asked what these systems do that the matrix never thought to ask, and found 636 candidates in 71 clusters, 506 of them carried by 77 changes in eleven repositories. What it found per area, and the twenty-two decisions Ruben took on 2026-09-14, are on [competitor discovery, September 2026](../research/competitor-discovery-2026-09.md).

## TEC BPM RFP Template Coverage

Coverage against the [TEC BPM RFP Template](https://www.tec-consulting.de/) zaaksysteem module (is_module=1):

| TEC Code | Capability | Feature |
|----------|------------|---------|
| 1.1 | Graphical Designer | Workflow Engine (SVG canvas) |
| 1.2 | Workflow | Workflow Engine, Case Management |
| 1.3 | Events | ZGW Notificaties API, Signalering Widgets |
| 1.4 | Task Allocation | Task Management, Roles & Decisions |
| 1.5 | Business Rules | ZGW Business Rules Compliance |
| 1.6 | Business Controls | Workflow Guards (role-check, field-value, date-range) |
| 1.7 | Data Modeling | OpenRegister Integration (dossiq_register.json) |
| 1.8 | Process Variable Binding | Workflow Engine (field-update actions) |
| 1.9 | Manual or User-Initiated Tasks | Task Management, Zaak Intake Flow |
| 1.10 | Due Dates | Case Deadlines, Milestone Tracking, Doorlooptijd |
| 1.11 | Process Linkage | Deelzaak Support, Sub-case creation |
| 2.1 | Roles and Users | Roles & Decisions |
| 2.2 | Role Management | Case Types (roleType schema) |
| 2.3 | User Profiles | My Work, Werkvoorraad |
| 2.4 | User Assignment Algorithms | Werkvoorraad (claim), Task assignment |
| 2.5 | Timers | Deadline tracking, signalering |
| 3.2 | Versioning | Case Definition Portability |
| 3.4 | Export Format | Case Definition Portability |
| 3.5 | Import Format | Base Register Seed Data |
| 4.1–4.7 | Form Management | Zaak Intake Flow, VTH Workflow (planned) |
| 5.1 | To-do List | My Work, Werkvoorraad |
| 5.2 | Watch List | Signalering Widgets |
| 5.3 | Reports | Doorlooptijd Dashboard |
| 5.4 | Search and Query | Case Management (list view filters) |
| 5.5 | Task Information | Task Management, Case Dashboard View |
| 5.6 | Collaboration | Case Sharing & Collaboration |
| 6.1 | Instance Management | Case Management (CRUD) |
| 6.2 | Workflow Initiation | Zaak Intake Flow, Workflow Engine |
| 6.3 | Workflow Monitoring | Doorlooptijd Dashboard, Signalering Widgets |
| 6.4 | Workflow Statistics | Doorlooptijd Dashboard |
| 6.5 | Audit Trails | Case Management (audit trail), OpenRegister Integration |
| 6.6 | Resource Organization | Roles & Decisions, Multi-Tenant SaaS |
| 7.1 | Performance Data | Prometheus Metrics, Doorlooptijd Dashboard |
| 7.2 | Trend Analysis | Doorlooptijd Dashboard (12-month trend) |

## Spec-to-Feature Mapping

Used by the `/opsx:archive` skill to update the correct feature doc when archiving a change.

```
case-management       → case-management.md
case-types            → case-types.md
task-management       → task-management.md
roles-decisions       → roles-decisions.md
roles-decisions-mvp   → roles-decisions.md
dashboard             → dashboard.md
dashboard-mvp         → dashboard.md
my-work               → my-work.md
admin-settings        → admin-settings.md
administration        → administration.md
openregister-integration → openregister-integration.md
base-register-seed-data → base-register-seed-data.md
workflow-engine-enhancement → workflow-engine-enhancement.md
vth-module            → vth-module.md
vth-workflow-configuration → vth-workflow-configuration.md
bezwaar-beroep-workflow → bezwaar-beroep-workflow.md
besluitvorming-workflow → besluitvorming-workflow.md
bw-parafering         → bw-parafering.md
deelzaak-support      → deelzaak-support.md
doorlooptijd-dashboard → doorlooptijd-dashboard.md
signalering-widgets   → signalering-widgets.md
gis-integration       → gis-integration.md
milestone-tracking    → milestone-tracking.md
zaak-intake-flow      → zaak-intake-flow.md
zaaktype-configuratie → zaaktype-configuratie.md
complaint-management  → complaint-management.md
consultation-management → consultation-management.md
woo-case-type         → woo-case-type.md
legesberekening       → legesberekening.md
case-email-integration → case-email-integration.md
appointment-scheduling → appointment-scheduling.md
case-sharing-collaboration → case-sharing-collaboration.md
case-definition-portability → case-definition-portability.md
mijn-overheid-integration → mijn-overheid-integration.md
mobiel-inspectie      → mobiel-inspectie.md
stuf-support          → stuf-support.md
ai-assisted-processing → ai-assisted-processing.md
register-i18n         → register-i18n.md
multi-tenant-saas     → multi-tenant-saas.md
prometheus-metrics    → prometheus-metrics.md
case-dashboard-view   → case-dashboard-view.md
werkvoorraad          → werkvoorraad.md
case-management-extended → case-management.md
case-sharing-collaboration → case-sharing-collaboration.md
zgw-autorisaties-api  → zgw-apis.md
zgw-documenten-api    → zgw-apis.md
zgw-notificaties-api  → zgw-apis.md
zgw-newman-test-suite → zgw-apis.md
zgw-business-rules-compliance → zgw-apis.md
create-dossiq-app    → app-scaffold.md
dossiq-app-scaffold  → app-scaffold.md
dossiq-object-store  → app-scaffold.md
dossiq-case-management → case-management.md
start-case-widget     → start-case-widget.md
```
