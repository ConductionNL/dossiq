---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# vth-dso-integration Specification

## Purpose
Integrates DSO (Digitaal Stelsel Omgevingswet) permit requests with VTH case handling by auto-creating cases from a DSO verzoek, mapping STAM 2.0 fields, and flagging cases for manual initiator linking when a BRP lookup fails. Dispatches status-change events on case transitions so OpenConnector can push status back to DSO-LV, and tracks DSO case deadlines daily with warnings before flagging overdue cases.
## Requirements
### Requirement: DSO verzoek intake and case creation

The system SHALL auto-create a case from a DSO verzoek, mapping STAM 2.0 fields and resolving references, and flag the case for manual linking when a BRP lookup fails.

**Spec ref**: REQ-VTH-006-A, REQ-VTH-006-B

#### Scenario: Verzoek creates a case

- **WHEN** a DSO vergunningaanvraag object is created and the listener triggers
- **THEN** a case SHALL be created with the correct zaaktype and pre-filled data (activiteiten, locatie/BAG, initiatiefnemer, bijlagen), transitioned to "Aanvraag ontvangen" with a notification

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

