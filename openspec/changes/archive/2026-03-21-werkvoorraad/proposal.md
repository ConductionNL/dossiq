# Werkvoorraad (Work Queue) Specification

## Problem
Werkvoorraad extends the existing My Work personal view with team-level work queue management. While My Work (`../my-work/spec.md`) shows a single user's assigned cases and tasks, Werkvoorraad provides team leads and managers with oversight of the full team workload: unassigned cases, workload distribution, bottlenecks, and reassignment capabilities.
**Tender demand**: 23% of tenders (16/69) explicitly require werkvoorraadlijsten and team overview. The capability is also implicit in the 86% that require "gebruikersbeheer en autorisatie" -- managers need to see and manage their team's work.
**Relationship to existing specs**: This spec EXTENDS `my-work` (personal view) and `task-management` (individual tasks). It does NOT replace them. Werkvoorraad adds the team/management layer on top.
**Standards**: CMMN 1.1 (work queue patterns), BPMN 2.0 (resource allocation), GEMMA werkvoorraad referentiecomponent
**Feature tier**: V1 (team overview, unassigned queue, reassignment), V2 (workload balancing, capacity planning, SLA monitoring)
**Competitive context**: Dimpact ZAC implements werkvoorraad as a Solr-backed worklist with separate routes for "mijn zaken" and "werkvoorraad zaken" (unassigned cases in user's groups). ZAC uses Keycloak groups for team scoping and OPA policies for access control. Flowable provides configurable work queues via CMMN case plan models with role-based task assignment. Procest should leverage Nextcloud groups for team scoping, avoiding the need for separate identity infrastructure.

## Proposed Solution
Implement Werkvoorraad (Work Queue) Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the werkvoorraad specification.

## Success Criteria
#### Scenario WV-01a: Team overview for manager
#### Scenario WV-01b: Unassigned work queue
#### Scenario WV-01c: Cross-team manager view
#### Scenario WV-01d: No team membership
#### Scenario WV-01e: Empty team queue
