# supplier-portal Specification — Member 06: Tender Frontend

---
status: proposed
---

## Purpose

Render the tender list and detail UI consuming the member 05 API, with sorting, filtering, status
badges, and conditional award/rejection rendering.

## ADDED Requirements

### Requirement: Tender List UI

The system SHALL display the supplier's tenders in a sortable, filterable list with status badges.

#### Scenario: List renders with badges and supports filtering

- GIVEN a user with finance, sales, or admin role opens the Tenders tab
- WHEN the list loads from the tender API
- THEN each tender SHALL show its title, status badge, submitted date, and value
- AND the user SHALL be able to sort by clicking column headers and filter by status, date range,
  and a case-insensitive title search

### Requirement: Tender Detail UI

The system SHALL render status-specific tender detail and document download controls.

#### Scenario: Detail renders conditional award/rejection sections

- GIVEN a user opens a tender detail
- WHEN the tender is awarded
- THEN the award date and award-letter download button SHALL be shown
- AND WHEN the tender is rejected THEN the rejection reason, appeal deadline, and anonymized
  evaluation-report download button SHALL be shown
