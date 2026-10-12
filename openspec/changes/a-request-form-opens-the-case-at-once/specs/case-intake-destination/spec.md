# case-intake-destination Delta: a-request-form-opens-the-case-at-once

**Status**: draft
**Scope**: dossiq case schema, intake listeners, portal actions, case-type publishing. Implements hydra `form-submits-into-its-destination-object` for dossiq.

## ADDED Requirements

### Requirement: The case create MUST carry its term fields

`receivedAt`, `termStartsAt` and `receivedOutsideWorkingHours` SHALL be set before the case is saved, so the create result holds them. A case in OpenRegister status `draft` SHALL carry none of them; they SHALL be set on the save that moves the case out of `draft` (decision 180). For a case opened through a form submit, `startDate` SHALL be the date of `termStartsAt`.

#### Scenario: A Sunday request returns its term in the submit response

- **GIVEN** a request form into case type `omgevingsvergunning` with a processing term of 8 weeks
- **WHEN** a resident submits it on Sunday 11 October 2026 at 21:40
- **THEN** the submit response carries the case `identifier`, `receivedAt` 2026-10-11T21:40, `termStartsAt` 2026-10-12 and `deadline` 2026-12-07
- **AND** the case audit trail holds one create, not a create and an update

#### Scenario: A draft case starts no term
- **GIVEN** a resident who saves a request form as a draft on Friday
- **WHEN** the case is read
- **THEN** it is in status `draft` with no `receivedAt`, `termStartsAt` or `deadline`
- **AND** no acknowledgement is queued

#### Scenario: Sending the draft starts the term
- **GIVEN** that draft, completed and sent on Sunday 11 October 2026 at 21:40
- **WHEN** the case leaves `draft`
- **THEN** `receivedAt` is 2026-10-11T21:40 and `termStartsAt` is 2026-10-12
- **AND** the acknowledgement is queued once

### Requirement: The confirmation fields MUST be marked on the case schema

`identifier`, `receivedAt`, `termStartsAt` and `deadline` SHALL carry `x-openregister.confirmation: true`. `identifier`, `receivedAt`, `termStartsAt` and `receivedOutsideWorkingHours` SHALL carry `x-openregister.serverSet: true`.

#### Scenario: A form without the server fields passes the destination check

- **GIVEN** a request form mapping `title`, `requester`, `communicationChannel` and `confidentiality`, with fixed `caseType`
- **WHEN** it is validated against the case schema
- **THEN** the validator returns zero findings

### Requirement: Publishing a case type MUST re-check the forms bound to it

Publishing a case type SHALL validate every form bound to it against the case schema plus that type's `intakeRequirements.requiredBeforeCreation`. A finding SHALL block the publish or unpublish the form, per the `formDestinationBreak` setting.

#### Scenario: A new intake requirement catches a form that lacks it

- **GIVEN** a published portal form bound to case type `klacht` that does not ask for `communicationChannel`
- **WHEN** `communicationChannel` is added to that type's `requiredBeforeCreation` and the type is published
- **THEN** the publish response names the form with finding `required-unmapped`

### Requirement: A portal filing MUST NOT be held in an intermediate object

The portal's bezwaar and klacht actions SHALL submit into `case`. The `portaalVerzoek` schema SHALL be drained by `occ dossiq:intake:drain-portaalverzoek`, which turns each object into a case or reports it with findings, and SHALL be removed when the report shows zero pending.

#### Scenario: An old portaalVerzoek becomes a case
- **GIVEN** a stored `portaalVerzoek` of kind `bezwaarschrift` against case Z/2026/09128
- **WHEN** the drain runs
- **THEN** a bezwaar case exists with `tegenZaak` Z/2026/09128 and the old id as `externalReference`
- **AND** the report counts it as delivered
