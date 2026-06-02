# supplier-portal Specification — Member 08: Invoice Frontend

---
status: proposed
---

## Purpose

Render the invoice list, detail, and age-analysis UI consuming the member 07 API, with status
badges, expected-payment-date display, and bucket filtering.

## ADDED Requirements

### Requirement: Invoice List and Detail UI

The system SHALL display invoices in a filterable list and render the expected payment date in the
detail view.

#### Scenario: Approved invoice shows the forecast prominently

- GIVEN a user with finance or admin role opens an approved invoice
- WHEN the detail renders
- THEN the expected payment date SHALL be shown in a highlighted green box
- AND the list SHALL show status badges and support filtering by status, date range, and amount

### Requirement: Age Analysis UI

The system SHALL render a stacked age-analysis bar with clickable buckets that filter the invoice
list.

#### Scenario: Clicking a bucket filters the list

- GIVEN the age-analysis bar is displayed with 0–30, 30–60, 60–90, and 90+ buckets
- WHEN the user clicks the 90+ bucket
- THEN the invoice list SHALL filter to invoices in that age range
- AND invoices more than 90 days overdue SHALL carry a red badge in the list
