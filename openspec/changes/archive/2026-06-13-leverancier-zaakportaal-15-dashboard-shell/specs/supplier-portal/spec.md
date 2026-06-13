# supplier-portal Specification — Member 15: Dashboard Shell & Layout

---
status: proposed
---

## Purpose

Provide the portal shell — layout, role-aware navigation, dashboard summary cards, and profile menu
— that ties the feature views together. Reads the scoped feature APIs for summary counts.

## ADDED Requirements

### Requirement: Portal Shell and Role-Aware Navigation

The system SHALL render a consistent portal layout whose navigation shows only the tabs allowed for
the user's role.

#### Scenario: Navigation follows the user role

- GIVEN an authenticated user opens the portal
- WHEN the shell renders
- THEN the layout SHALL show the header, role-aware nav, and a profile menu with name, role, "Mijn
  Gegevens", and logout
- AND only the tabs permitted for the user's role SHALL be visible

### Requirement: Dashboard Summary Cards

The system SHALL render at-a-glance summary cards aggregating the supplier's key figures.

#### Scenario: Summary cards show counts and link to features

- GIVEN the dashboard loads for an authenticated supplier
- WHEN the summary renders
- THEN four cards SHALL show open tenders, unpaid invoices, expiring contracts, and a KPI headline
  with counts/status badges
- AND each card SHALL link into its corresponding feature view
- AND clicking logout SHALL destroy the session and redirect to login
