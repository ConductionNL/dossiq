# Procest — Feature Overview

Procest is a Nextcloud case management app (zaaksysteem) for Dutch municipalities, covering general case handling (zaakgericht werken), VTH permits/supervision/enforcement, objection and appeal workflows, B&W decision-making, and workflow automation. All data is stored in OpenRegister — Procest owns no database tables.

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
| TEC BPM RFP Template — Process Modeling | Sections 1.1–1.11 | Partial |
| TEC BPM RFP Template — Security Management | Sections 2.1–2.5 | Partial |
| TEC BPM RFP Template — Workflow Portal | Sections 5.1–5.6 | Partial |
| TEC BPM RFP Template — Monitoring & Management | Sections 6.1–6.6 | Partial |
| CMMN 1.1 (OMG) | Case Plan Model, HumanTask, Milestone | Partial |
| Forum Standaardisatie — REST-API Design Rules | [forumstandaardisatie.nl](https://forumstandaardisatie.nl/open-standaarden/rest-api-design-rules) | Implemented |
| Forum Standaardisatie — NL GOV CloudEvents | [forumstandaardisatie.nl](https://forumstandaardisatie.nl/open-standaarden/nl-gov-cloudevents) | Planned |
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
| Zaaktype Configuratie | Field mapping between Procest's internal model and Dutch ZGW resource types | ZGW ZRC/ZTC/BRC/DRC | Implemented | [zaaktype-configuratie.md](zaaktype-configuratie.md) |
| Task Management | Task work items linked to cases, with assignees, due dates, and status lifecycle | TEC BPM 5.1, CMMN HumanTask | Implemented | [task-management.md](task-management.md) |
| Roles & Decisions | Case participant role assignment (behandelaar, initiator, adviseur) and formal decision recording | GEMMA Zaakafhandel, ZGW BRC, ZGW ZRC Rol | Partial | [roles-decisions.md](roles-decisions.md) |
| Dashboard | Landing page with KPI cards (open, overdue, completed, my tasks), status chart, and work list | TEC BPM 5.3, 6.3 | Implemented | [dashboard.md](dashboard.md) |
| My Work | Personal work queue showing cases and tasks assigned to the current user | TEC BPM 5.1, 5.5 | Implemented | [my-work.md](my-work.md) |
| Werkvoorraad | Team-level queue of unassigned cases available for claiming | TEC BPM 5.1, GEMMA Zaakafhandel | Planned | [werkvoorraad.md](werkvoorraad.md) |
| Administration | Nextcloud admin panel for schema configuration, ZGW mapping, and seed data import | Nextcloud OCP | Implemented | [administration.md](administration.md) |
| Admin Settings | Configuration page for register/schema UUID mappings and version information | Nextcloud OCP | Implemented | [admin-settings.md](admin-settings.md) |
| OpenRegister Integration | All data stored as OpenRegister objects — Procest owns no database tables | OpenRegister API | Implemented | [openregister-integration.md](openregister-integration.md) |
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
| ZGW APIs | Full ZGW API suite: ZRC, ZTC, DRC, BRC, AC, NRC — VNG Newman test suite compliance | ZGW 1.x, VNG | Implemented | [zgw-apis.md](zgw-apis.md) |
| Zaak Intake Flow | Structured intake form with case type selection, auto-numbering, and deadline calculation | ZGW ZRC, DSO | Planned | [zaak-intake-flow.md](zaak-intake-flow.md) |
| Complaint Management | AWB-compliant klachtenprocedure with hearings, deadlines, and ombudsman escalation | Awb Hoofdstuk 9, GEMMA | Planned | [complaint-management.md](complaint-management.md) |
| Consultation Management | Public participation (inspraak) with response collection and nota van beantwoording | Omgevingswet, Awb | Planned | [consultation-management.md](consultation-management.md) |
| WOO Case Type | Open Government Act disclosure requests with redaction, zienswijze, and publication | Woo, Forum Standaardisatie | Planned | [woo-case-type.md](woo-case-type.md) |
| Legesberekening | Automated municipal fee calculation based on the legesverordening | Legesverordening | Planned | [legesberekening.md](legesberekening.md) |
| Case Email Integration | Link email communication to cases and create cases from incoming email | ZGW, Nextcloud Mail | Planned | [case-email-integration.md](case-email-integration.md) |
| Appointment Scheduling | Schedule hearings, consultations, and inspections linked to cases | Nextcloud Calendar | Planned | [appointment-scheduling.md](appointment-scheduling.md) |
| Case Sharing & Collaboration | Cross-department and federated case sharing with role-based access | Nextcloud Federation | Planned | [case-sharing-collaboration.md](case-sharing-collaboration.md) |
| Case Definition Portability | Export and import case type definitions between Procest instances | OpenCatalogi | Planned | [case-definition-portability.md](case-definition-portability.md) |
| MijnOverheid Integration | Publish case status and notifications to the national citizen portal | Logius Berichtenbox, DigiD | Planned | [mijn-overheid-integration.md](mijn-overheid-integration.md) |
| Mobiel Inspectie | Mobile-optimized inspection interface with checklists, photo capture, GPS, and offline sync | GEMMA Mobiel toezicht | Planned | [mobiel-inspectie.md](mobiel-inspectie.md) |
| StUF Support | Legacy StUF-ZKN/BG SOAP/XML bridge for connecting to older government systems | StUF-ZKN, StUF-BG | Planned | [stuf-support.md](stuf-support.md) |
| AI-Assisted Processing | Document summarization, auto-classification, anonymization (Presidio), and deadline risk prediction | NL GOV, Nextcloud AI | Planned | [ai-assisted-processing.md](ai-assisted-processing.md) |
| Register i18n | Full Dutch + English translation using Nextcloud gettext/l10n infrastructure | Forum Standaardisatie i18n | Partial | [register-i18n.md](register-i18n.md) |
| Multi-Tenant SaaS | Tenant isolation, per-tenant configuration, and NL Design System theming per tenant | Nextcloud Groups | Planned | [multi-tenant-saas.md](multi-tenant-saas.md) |
| Prometheus Metrics | `/metrics` endpoint for Prometheus scraping with SLA compliance and queue depth metrics | OpenMetrics | Planned | [prometheus-metrics.md](prometheus-metrics.md) |
| App Scaffold | PHP/Vue app foundation, OpenRegister wiring, Pinia stores, and build system | Nextcloud OCP | Implemented | [app-scaffold.md](app-scaffold.md) |

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
| 1.7 | Data Modeling | OpenRegister Integration (procest_register.json) |
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
create-procest-app    → app-scaffold.md
procest-app-scaffold  → app-scaffold.md
procest-object-store  → app-scaffold.md
procest-case-management → case-management.md
```
