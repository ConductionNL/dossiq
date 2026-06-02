# supplier-portal Specification — Member 12: Master Data Self-Service

---
status: proposed
---

## Purpose

Let suppliers update master data with per-field approval policy: address/contact auto-applied, IBAN
via re-auth + 4-eyes, accreditation submitted for verification. Consumes the `Supplier` schema and
case types from member 01.

## ADDED Requirements

### Requirement: Auto-Applied Address and Contact Updates

The system SHALL apply address and contact-person changes immediately and audit them.

#### Scenario: Address change applies immediately

- GIVEN an admin submits a valid address change
- WHEN the change is saved
- THEN the Supplier record SHALL be updated immediately, an audit entry written, and a
  confirmation email sent to the primary contact

### Requirement: IBAN Change Requires Re-Auth and 4-Eyes Approval

The system SHALL hold an IBAN change behind re-authentication and a 4-eyes Procest workflow before
it takes effect.

#### Scenario: IBAN change opens a 4-eyes case and is not applied yet

- GIVEN a user submits an IBAN change after re-authenticating
- WHEN the request is submitted
- THEN a Procest zaak of type `Leverancier-IBAN-wijziging` with a 4-eyes workflow SHALL be created
- AND the Supplier IBAN SHALL NOT change until both reviewers approve
- AND on approval the IBAN SHALL be updated and the supplier notified; on rejection the old IBAN
  SHALL remain active

### Requirement: Accreditation Submission for Verification

The system SHALL submit SBI/accreditation changes for verification without auto-applying them.

#### Scenario: Accreditation change is queued for verification

- GIVEN a user submits an accreditation change with proof attachments
- WHEN the request is submitted
- THEN a Procest zaak of type `Leverancier-accreditatie-verificatie` SHALL be created and the
  change SHALL NOT be applied until the municipal team approves
