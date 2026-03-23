# Dashboard Specification

## Problem
The dashboard is the landing page of the Procest app. It provides an at-a-glance overview of case management activity: KPI cards with headline metrics, status and type distribution charts, an overdue cases panel, a personal workload preview, a recent activity feed, and quick actions. The dashboard aggregates data across all cases visible to the current user (respecting RBAC via OpenRegister).
**Feature tiers**: MVP (KPI cards, status chart, overdue panel, my work preview, activity feed, quick actions, empty state, refresh); V1 (average processing time KPI, case type breakdown chart, SLA compliance widget, workload distribution)

## Proposed Solution
Implement Dashboard Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the dashboard specification.

## Success Criteria
#### Scenario DASH-001a: Open cases count with today indicator
#### Scenario DASH-001b: Overdue cases count with action indicator
#### Scenario DASH-001c: Completed this month with average processing days
#### Scenario DASH-001d: My tasks count with due-today indicator
#### Scenario DASH-001e: Zero values in KPI cards
