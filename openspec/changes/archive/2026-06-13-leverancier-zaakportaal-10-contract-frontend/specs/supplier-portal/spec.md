# supplier-portal Specification — Member 10: Contract Frontend

---
status: proposed
---

## Purpose

Render the contract list and detail UI consuming the member 09 API, with expiry warning badges and
the renewal-request modal.

## ADDED Requirements

### Requirement: Contract List UI with Expiry Warnings

The system SHALL display contracts sorted by nearest expiry and badge those within 90 days.

#### Scenario: Expiring contract shows a warning badge

- GIVEN a user with contracts or admin role opens the Contracts tab
- WHEN a contract has `renewalWarning` = true
- THEN the row SHALL show an orange "Vervalt over [n] dagen" badge and a highlighted background
- AND contracts SHALL be sorted by end date with the nearest expiry first

### Requirement: Renewal Request UI

The system SHALL offer a renewal-request action for eligible contracts and confirm submission.

#### Scenario: Requesting renewal confirms and disables the button

- GIVEN a manual-renewal contract within 90 days of expiry
- WHEN the user clicks "Verlenging aanvragen" and confirms in the modal
- THEN the renewal request SHALL be submitted to the backend
- AND the button SHALL change to "Verlenging aangevraagd op [date]" and become disabled
