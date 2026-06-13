# supplier-portal Specification — Member 05: Tender Visibility Backend

---
status: proposed
---

## Purpose

Serve tender status, award/rejection detail, appeal deadlines, and anonymized documents over a
supplier-scoped API. Consumes the `SupplierTender` schema from member 01 and scoping from member
04.

## ADDED Requirements

### Requirement: Tender Status and Detail API

The system SHALL expose a supplier-scoped API returning tender status, dates, values, and
status-specific fields including the legally mandated rejection motivation.

#### Scenario: Awarded tender exposes award detail

- GIVEN a scoped request for an awarded tender
- WHEN the detail endpoint responds
- THEN it SHALL include the status, award date, contract value, and an award-letter download link

#### Scenario: Rejected tender exposes motivation and appeal deadline

- GIVEN a scoped request for a rejected tender
- WHEN the detail endpoint responds
- THEN it SHALL include the rejection reason (per Aanbestedingswet 2012 art. 2.130), an appeal
  deadline of 20 days from the rejection date, and an anonymized evaluation-report download link
- AND a request for a tender outside the supplier's scope SHALL return 403 or 404

### Requirement: Anonymized Document Download

The system SHALL serve the evaluation report only when it is marked anonymized and SHALL log the
download.

#### Scenario: Evaluation report download is gated on anonymization

- GIVEN an awarded or rejected tender with an evaluation report
- WHEN the user requests the report
- THEN the system SHALL verify the PDF is marked anonymized before serving it with
  `Content-Disposition: attachment`
- AND the download event SHALL be written to the audit trail
