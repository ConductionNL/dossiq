# Adviesaanvragen (inter-departmental consultations)

> **n8n workflows:** see [`docs/n8n-consultation-workflows.md`](../n8n-consultation-workflows.md) for the webhook contracts and installation guide.
> **Note:** [`consultation-management.md`](consultation-management.md) covers public participation / inspraak. This page covers **inter-departmental and external advisory consultations** delivered by `consultation-management` change.

Structured inter-departmental and external advisory consultations (adviesaanvragen) as a first-class entity in Dossiq. Replaces email-based informal advice exchange with tracked, auditable departmental coordination per Awb articles 3:5-3:9.

## Specs

- `openspec/changes/consultation-management/specs/consultation-management/spec.md`

## Features

### First-class consultation entity (V1)
- Consultations stored as OpenRegister objects linked to a parent `case`.
- Auto-generated number `ADV-{year}-{seq}`.
- Bidirectional navigation between consultation and case.

### Lifecycle with deadline enforcement (V1)
- Status transitions: `open` → `ontvangen` → `in_behandeling` → `advies_uitgebracht` → `afgesloten` (plus `ingetrokken`).
- T-5 deadline warning (configurable offset) and overdue escalation, driven by n8n.
- Extension requests with approval flow.

### Structured document exchange (V1)
- Context documents linked (not copied) from the parent case.
- Consulted party uploads advice via the case folder (`Adviezen/{ADV-...}/`); document version history preserved.
- Document access scoping enforces BIO confidentiality rules.

### Activity timeline integration (V1)
- All six lifecycle events appear chronologically on the case's activity timeline.
- Overdue events visually distinct (red/amber).

### Dashboard widgets & KPIs (V1)
- "Openstaande adviesaanvragen" widget per department.
- Performance metrics (average response time, on-time rate, advice outcome distribution) for coordinators.
- Bottleneck detection alerts when a body's overdue rate exceeds 20%.

### Configurable consultation types per zaaktype (V1)
- Admin defines mandatory and optional consultation types per case type.
- Mandatory consultations are auto-created on case creation and block case progression to the decision milestone until completed.

### Advisory body registry (V1)
- `advisoryBody` records for internal departments (Nextcloud group-backed) and external organisations (email + secure response link).
- Specialisations as searchable tags.

### Parallel + sequential consultation patterns (V1)
- Parallel: all-mandatory-must-complete gate.
- Sequential: dependency on another consultation finishing first.

### Assignment & reassignment (V1)
- Coordinator assigns a specific user within the consulted department.
- Reassignment notifies both old and new assignee.

### External advisory body via secure link (V1)
- 256-bit single-use token; expires on closure or 90 days.
- External body responds via secure link (advice document + outcome + notes).
- All access logged for BIO 8.3.1.

### n8n workflow integration (V1)
- `consultation-deadline-monitor`: daily T-5 warning + overdue escalation.
- `consultation-email-fanout`: external advisory body notification.
- `consultation-bottleneck-detection`: daily overdue-rate threshold alert.

## Entities

- `consultation`
- `adviceResponse`
- `advisoryBody`

See [ADR-000](../../openspec/architecture/adr-000-data-model.md) for field definitions.
