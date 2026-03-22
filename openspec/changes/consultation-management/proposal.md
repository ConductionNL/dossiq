## Why

Inter-departmental consultations (adviesaanvragen) currently happen via email, losing auditability. This implements structured consultation as first-class entities with lifecycle, document exchange, structured responses with conditions, timeline integration, and a consultation dashboard.

## What Changes

1. Consultation schema in procest_register.json
2. ConsultationService for CRUD, lifecycle management, overdue detection
3. ConsultationController with REST API
4. ConsultationPanel Vue component for case detail view
5. ConsultationDashboard Vue component for pending consultations overview
6. Route additions

## Impact

- New schema, service, controller, 2 Vue components, route additions
- Extends case detail view with consultations panel
