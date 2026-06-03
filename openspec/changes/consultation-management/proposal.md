# Consultation Management Implementation

## Why
Inter-departmental and external advisory consultations (adviesaanvragen) are currently exchanged via email, which destroys auditability, version control, and deadline enforcement. Awb articles 3:5-3:9 give consultation a legal status: the decision-maker must verify advice was produced diligently and respect reasonable response deadlines. Without structured consultations, municipalities cannot prove diligence on omgevingsvergunning, monumentenadvies, milieuadvies, or welstandstoets paths, and case workers have no central view of what is outstanding or blocking case completion. ArkCase and Flowable both model this concept as a sub-case linked to a parent — Procest needs the same primitive built on top of OpenRegister.

## What Changes
1. New `consultation`, `advisoryBody`, and `adviceResponse` schemas in `procest_register.json`.
2. `ConsultationService` for CRUD, lifecycle transitions, overdue detection, extension requests, and dependency enforcement.
3. `ConsultationController` REST API with routes for parent-case consultations, the consulted-party inbox, and external secure-link responses.
4. `ConsultationPanel.vue` (case-detail tab) and `ConsultationDashboard.vue` (department inbox) Vue components.
5. Parallel and sequential consultation patterns with mandatory-gate enforcement at the milestone level.
6. External advisory-body email path with secure response links for bodies without Nextcloud accounts.
7. Notifications, deadline monitoring, and activity-timeline integration via n8n.

## Impact
- Case detail view gains an "Adviezen" tab and a consultation summary badge.
- Dashboard gains a "Openstaande adviesaanvragen" widget and consultation performance KPIs.
- Mandatory consultations block case progression to decision milestones until completed.
- New cross-cutting integration with `milestone-tracking` for mandatory gates.

## Out of Scope
- Public participation / inspraak (citizen consultation on policy decisions).
- AI-generated advice drafting.
- Legal advice management with advocaat-client privilege.
