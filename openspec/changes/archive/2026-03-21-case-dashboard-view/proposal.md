# Case Dashboard View Specification

## Problem
The Case Dashboard View is the primary working screen for behandelaars. It combines all relevant information for a single case into one integrated view: timeline, documents, status, tasks, contactmomenten, besluiten, and linked objects. While the Case Management spec (`../case-management/spec.md`) defines the data model and individual panels (REQ-CM-06 through REQ-CM-13), this spec defines how those panels are composed into a cohesive working screen with interactions between them.
**Tender demand**: This is not a separately tendered capability but underpins the 83% (57/69) that require "zaakgericht werken." Every tender evaluation includes a demo of the case detail screen. Usability of this view is the #1 factor in user acceptance.
**Relationship to existing specs**: This spec COMPOSES elements from `case-management` (panels), `task-management` (task section), `roles-decisions` (participants, decisions), and `dashboard` (app-level overview). It adds layout, interactions, and cross-panel behaviors.
**Feature tier**: MVP (layout, panel composition, navigation), V1 (configurable layout, quick actions, keyboard shortcuts, contactmomenten, linked objects)
**Competitive context**: Dimpact ZAC uses an Angular SPA with Material UI and a tabbed case detail view (zaak-view). Key features include: full audit trail in a history tab, WebSocket-driven real-time updates (screen events), BAG object linking, and betrokkenen management. The ZAC case view integrates with Solr for search and Flowable for process state. Procest uses the `CnDetailPage` layout from `@conduction/nextcloud-vue` with a sidebar model, providing a more Nextcloud-native feel.

## Proposed Solution
Implement Case Dashboard View Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the case-dashboard-view specification.

## Success Criteria
#### Scenario CDV-01a: Load case dashboard
#### Scenario CDV-01b: Load case from different entry points
#### Scenario CDV-01c: Case not found
#### Scenario CDV-01d: Loading state
#### Scenario CDV-02a: Status change updates timeline
