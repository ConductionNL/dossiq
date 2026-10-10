---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# vth-dso-integration Specification

## Purpose
Integrates DSO (Digitaal Stelsel Omgevingswet) permit requests with VTH case handling by auto-creating cases from a DSO verzoek, mapping STAM 2.0 fields, and flagging cases for manual initiator linking when a BRP lookup fails. Dispatches status-change events on case transitions so OpenConnector can push status back to DSO-LV, and tracks DSO case deadlines daily with warnings before flagging overdue cases.

## Requirements

### Requirement: DSO verzoek intake and case creation

The system SHALL auto-create a case from a DSO verzoek that integriq has mapped, filing it on the first case type in the verzoek's `mappedCaseTypes` that resolves, at that type's initial status, and flag the case for manual linking when a BRP lookup fails.

**Spec ref**: REQ-VTH-006-A, REQ-VTH-006-B

@e2e exclude backend listener on an OpenRegister object event; covered by PHPUnit and the live intake run, no browser surface

#### Scenario: Verzoek creates a case

- **WHEN** integriq writes a `dso_verzoek` with status `mapped` and the listener triggers
- **THEN** a case SHALL be created with the case type resolved from `mappedCaseTypes`, the type's initial status, `dsoStatus` "submitted" and the verzoek's reference in `permitApplicationRef`

#### Scenario: BRP lookup failure

- **WHEN** the initiator BRP lookup fails during intake
- **THEN** the case SHALL be flagged "Awaiting manual initiator linking"

### Requirement: Status pushback to DSO-LV

The system SHALL dispatch a status-change event on DSO case transitions so OpenConnector can push status to DSO-LV.

**Spec ref**: REQ-VTH-006-C

#### Scenario: Status change dispatches an event

- **WHEN** a DSO case status changes
- **THEN** a VergunningStatusChangedEvent SHALL be dispatched with vergunningaanvraagRef, old/new status, timestamp and userId, including the beschikking URL for Verleend/Geweigerd

### Requirement: DSO deadline tracking and warnings

The system SHALL evaluate DSO case deadlines daily and warn at thresholds before flagging overdue cases.

**Spec ref**: REQ-VTH-006-D

#### Scenario: Deadline warnings and overdue flag

- **WHEN** the daily deadline job evaluates DSO cases
- **THEN** notifications SHALL fire at 6 weeks and 2 weeks before the deadline, and at the deadline the case SHALL be flagged "Overdue" with transitions blocked until escalation

### Requirement: DSO intake is on by default with integriq

When integriq is installed and `dso_vergunningaanvraag_schema` was never set, the system SHALL set it to the id of integriq's `dso_verzoek` schema on install and on upgrade, found through integriq's register by slug. A value an administrator set, an empty value included, SHALL NOT be overwritten. While the key is empty, the administration overview SHALL warn that DSO intake is off.

@e2e exclude repair step and setup check; covered by PHPUnit and the live install run, no app page

#### Scenario: Fresh install with integriq

- **WHEN** dossiq is installed or upgraded, integriq is installed and the key is absent
- **THEN** the key SHALL hold the id of integriq's `dso_verzoek` schema, and a mapped verzoek SHALL become a case

#### Scenario: An administrator turned intake off

- **WHEN** an administrator set the key to an empty value and dossiq is upgraded
- **THEN** the key SHALL stay empty

#### Scenario: integriq is not installed

- **WHEN** dossiq is installed without integriq
- **THEN** the key SHALL stay absent, and the setup check SHALL warn that DSO intake is off

### Requirement: One path turns a DSO verzoek into a case

A DSO verzoek SHALL become a dossiq case only through the listener on integriq's mapped `dso_verzoek`, and one verzoek SHALL make at most one case. Dossiq SHALL expose no endpoint of its own that receives a DSO verzoek.

@e2e exclude backend intake path; covered by DsoIntakeHasOnePathTest and the live intake run, no browser surface

#### Scenario: The same verzoek arrives twice

- **WHEN** the listener sees a second write for a verzoek that already has a case
- **THEN** no second case SHALL be created, and the existing case SHALL be answered

#### Scenario: The former dossiq endpoint is called

- **WHEN** a client posts a STAM payload to `/apps/dossiq/api/vth/dso/intake`
- **THEN** no route SHALL answer it and no case SHALL be created
