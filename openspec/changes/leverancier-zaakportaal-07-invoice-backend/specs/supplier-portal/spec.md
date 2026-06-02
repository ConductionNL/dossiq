# supplier-portal Specification — Member 07: Invoice Payment Forecast Backend

---
status: proposed
---

## Purpose

Serve invoice status, expected payment date, age analysis, and dispute over a supplier-scoped API,
and alert on 90+ day overdue invoices. Consumes the `SupplierInvoice` schema from member 01 and
Decidesk mandate routing.

## ADDED Requirements

### Requirement: Expected Payment Date Calculation

The system SHALL calculate an invoice's expected payment date from its date, the Decidesk mandate
routing delay, and the payment-term days, with a safe fallback when Decidesk is unavailable.

#### Scenario: Approved invoice exposes a forecast date

- GIVEN an approved invoice with a 30-day payment term and a 5-day mandate routing delay
- WHEN the detail endpoint responds
- THEN the expected payment date SHALL equal invoice date + 5 + 30 days
- AND WHEN Decidesk is unavailable THEN a default 5-day routing delay SHALL be used rather than
  failing the request

### Requirement: Age Analysis and Overdue Alerting

The system SHALL bucket overdue invoices and email the supplier when an invoice passes 90 days
overdue.

#### Scenario: Age analysis returns correct buckets

- GIVEN a scoped age-analysis request
- WHEN it responds
- THEN it SHALL return counts and totals for the 0–30, 30–60, 60–90, and 90+ day buckets

#### Scenario: 90+ overdue triggers an alert email

- GIVEN an invoice crosses 90 days overdue
- WHEN the nightly overdue job runs
- THEN an alert email SHALL be sent to the supplier's primary contact
- AND a cross-supplier invoice request SHALL return 403
