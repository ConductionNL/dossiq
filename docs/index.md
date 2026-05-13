---
id: intro
title: Introduction
sidebar_position: 1
---

# Procest Documentation

Procest is a case management (zaakgericht werken) application for Nextcloud, designed for Dutch government organizations. It provides a complete case management solution built on top of OpenRegister.

## Screenshots

| Feature | Screenshot |
|---------|------------|
| Dashboard | ![Dashboard](/screenshots/dashboard.png) |
| My Work | ![My Work](/screenshots/my-work.png) |
| Cases | ![Cases](/screenshots/case-management.png) |
| Tasks | ![Tasks](/screenshots/task-management.png) |
| Case Types | ![Case Types](/screenshots/case-types.png) |
| Admin Settings | ![Settings](/screenshots/admin-settings.png) |
| ZGW Configuration | ![ZGW Config](/screenshots/zaaktype-configuratie.png) |

## Feature Documentation

### Core Features (with screenshots)

| Feature | Description | Status |
|---------|-------------|--------|
| [Dashboard](Features/dashboard.md) | Overview with case statistics, status chart, and personal work queue | Implemented |
| [Case Management](Features/case-management.md) | List, filter, and manage cases in table or card view | Implemented |
| [Case Dashboard View](Features/case-dashboard-view.md) | Individual case detail page with status, tasks, and documents | In development |
| [My Work](Features/my-work.md) | Personal work queue showing assigned cases and tasks | Implemented |
| [Task Management](Features/task-management.md) | List, create, and manage tasks associated with cases | Implemented |
| [Case Types](Features/case-types.md) | Configure case type definitions with ZGW-compliant properties | Implemented |
| [Admin Settings](Features/admin-settings.md) | Application configuration, schema mapping, and version info | Implemented |
| [Zaaktype Configuratie](Features/zaaktype-configuratie.md) | ZGW API field mapping between OpenRegister and Dutch ZGW standard | Implemented |

### Case Processing Features (text-only)

| Feature | Description | Status |
|---------|-------------|--------|
| [Werkvoorraad](Features/werkvoorraad.md) | Team work queue for unassigned cases | Planned |
| [Roles and Decisions](Features/roles-decisions.md) | Role assignment and formal decision recording | Partial |
| [Zaak Intake Flow](Features/zaak-intake-flow.md) | Case registration and intake process | Planned |
| [Complaint Management](Features/complaint-management.md) | Citizen complaint handling (klachtafhandeling) | Planned |
| [Consultation Management](Features/consultation-management.md) | Public participation and consultation processes | Planned |
| [Milestone Tracking](Features/milestone-tracking.md) | Case lifecycle checkpoint monitoring | Planned |
| [Case Email Integration](Features/case-email-integration.md) | Email-to-case and case correspondence | Planned |
| [WOO Case Type](Features/woo-case-type.md) | Open Government Act disclosure requests | Planned |
| [VTH Module](Features/vth-module.md) | Permits, supervision, and enforcement | Planned |
| [Case Sharing](Features/case-sharing-collaboration.md) | Multi-user and cross-org case collaboration | In development |
| [AI-Assisted Processing](Features/ai-assisted-processing.md) | LLM-powered case analysis and document processing | In development |

### Administrative Features (text-only)

| Feature | Description | Status |
|---------|-------------|--------|
| [Appointment Scheduling](Features/appointment-scheduling.md) | Meeting and hearing scheduling | Planned |
| [B&W Parafering](Features/bw-parafering.md) | Executive approval workflow | Planned |
| [Legesberekening](Features/legesberekening.md) | Municipal fee calculation | Planned |
| [Mobiel Inspectie](Features/mobiel-inspectie.md) | Mobile field inspection interface | Planned |

### Integration Features (text-only)

| Feature | Description | Status |
|---------|-------------|--------|
| [OpenRegister Integration](Features/openregister-integration.md) | Core data layer via OpenRegister | Implemented |
| [MijnOverheid Integration](Features/mijn-overheid-integration.md) | National citizen portal integration | Planned |
| [StUF Support](Features/stuf-support.md) | Legacy Dutch government data exchange | Planned |

### Platform Features (text-only)

| Feature | Description | Status |
|---------|-------------|--------|
| [Prometheus Metrics](Features/prometheus-metrics.md) | Production monitoring and alerting | Planned |
| [Register i18n](Features/register-i18n.md) | Multilingual support (nl/en) | Partial |
| [Base Register Seed Data](Features/base-register-seed-data.md) | Pre-configured case types and definitions | Implemented |
| [Multi-Tenant SaaS](Features/multi-tenant-saas.md) | Multi-organization support | Planned |
| [Case Definition Portability](Features/case-definition-portability.md) | Export/import case type configurations | Planned |

## Architecture

Procest is built as a Nextcloud app with the following architecture:

- **Frontend**: Vue.js single-page application within the Nextcloud framework.
- **Backend**: PHP (Nextcloud app structure with controllers, services, and mappers).
- **Data Layer**: OpenRegister (flexible register/schema/object model).
- **API**: ZGW-compatible REST API via configurable field mapping.
- **AI**: Integration with Nextcloud ExApps (Ollama, Presidio, etc.).
- **Theming**: NL Design System tokens via the nldesign Nextcloud app.
