## ADDED Requirements

### Requirement: Open data subject requests have a list of their own (REQ-DL-1)

The manifest page `PrivacyRequests` (route `/privacy/requests`, type `index`,
register `dossiq`, schema `case`) SHALL list the cases whose
`dataSubjectRequestType` is inzage, correctie or verwijdering, with quick
filters per kind, the received date, the term, the status and the handler. A
row SHALL open `CaseDetail`.

#### Scenario: An erasure request is listed under its kind
@e2e exclude measured against board DqPrivacy by the round 12 pixel check, not an e2e flow

- **GIVEN** a case with `dataSubjectRequestType` verwijdering
- **WHEN** you open the data subject requests and pick Erasure
- **THEN** the list SHALL show that case and no inzage or correctie case

### Requirement: Contracts are listed with where they stand (REQ-DL-2)

The manifest page `Contracts` (route `/procurement/contracts`, schema
`supplierContract`) SHALL list each contract with its supplier, value, end
date and `status`, with quick filters All, Active, Expires within 6 months
(`renewalWarning`) and Decision needed (a renewal or termination decision
pending). `supplierContract.status` SHALL be one of active,
renewal-decision, termination-decision and ending.

#### Scenario: A contract waiting on a renewal decision
@e2e exclude measured against board DqContracten by the round 12 pixel check, not an e2e flow

- **GIVEN** a contract with status renewal-decision
- **WHEN** you open Contracts and pick Decision needed
- **THEN** the list SHALL show that contract with the status Renewal: decision pending
