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

The system SHALL ship the settings-only case definition package `dso-intake`, installed automatically on install and on upgrade. Its setting names integriq's `dso_verzoek` schema by register (`integriq`, or the old `openconnector`) and slug, and the installer SHALL store that schema's id on this instance in `dso_vergunningaanvraag_schema` when the key was never set. A value an administrator set, an empty value included, SHALL NOT be overwritten. When integriq or its `dso_verzoek` schema is missing, the package SHALL be refused naming integriq and SHALL be tried again on the next upgrade. While the key is empty, the administration overview SHALL warn that DSO intake is off.

#### Scenario: Fresh install with integriq
@e2e tests/e2e/packages/dso-intake.spec.ts

- **GIVEN** an instance with integriq, where the key was never set
- **WHEN** dossiq is installed or upgraded
- **THEN** the administration overview SHALL report that DSO intake reads a schema
- **AND** that schema SHALL be integriq's `dso_verzoek`

#### Scenario: An administrator turned intake off
@e2e exclude a settings precedence rule of the installer; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testSettingsNeverOverruleAnAdministratorsValue

- **WHEN** an administrator set the key to an empty value and dossiq is upgraded
- **THEN** the key SHALL stay empty

#### Scenario: integriq is not installed
@e2e exclude an instance without integriq; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAReferenceToAnAppThatIsNotInstalledRefusesThePackage

- **WHEN** dossiq is installed without integriq
- **THEN** the package SHALL be refused naming integriq, the key SHALL stay absent, and the setup check SHALL warn that DSO intake is off

### Requirement: One path turns a DSO verzoek into a case

A DSO verzoek SHALL become a dossiq case only through the listener on integriq's mapped `dso_verzoek`, and one verzoek SHALL make at most one case. Dossiq SHALL expose no endpoint of its own that receives a DSO verzoek.

@e2e exclude backend intake path; covered by DsoIntakeHasOnePathTest and the live intake run, no browser surface

#### Scenario: The same verzoek arrives twice

- **WHEN** the listener sees a second write for a verzoek that already has a case
- **THEN** no second case SHALL be created, and the existing case SHALL be answered

#### Scenario: The former dossiq endpoint is called

- **WHEN** a client posts a STAM payload to `/apps/dossiq/api/vth/dso/intake`
- **THEN** no route SHALL answer it and no case SHALL be created
