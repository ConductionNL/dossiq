---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# vth-mobile-inspection Specification

## Purpose
Enables field inspectors to carry out inspections on mobile devices through a service that returns a mobile-formatted checklist, uploads photos to Nextcloud, captures GPS with a manual fallback, and submits a validated inspection result. Provides a responsive single-column UI with type-specific inputs, a progress indicator, navigation, and offline draft support that syncs when connectivity returns.
## Requirements
### Requirement: Mobile inspection service

The system SHALL provide a mobile inspection service that returns a mobile-formatted checklist, handles photo upload to Nextcloud, captures GPS with a manual fallback, and submits a validated inspection result.

**Spec ref**: REQ-VTH-002-B, REQ-VTH-002-D

#### Scenario: Retrieve and submit a mobile inspection

- **WHEN** an inspector retrieves the checklist for a toezichtzaak and submits answers with required photos and GPS
- **THEN** the service SHALL store the photos as Nextcloud files, record GPS coordinates with timestamp, and create an InspectionResult

#### Scenario: Submission validation

- **WHEN** required checklist items are unanswered or required photos are missing
- **THEN** submission SHALL be rejected with a validation error

### Requirement: Responsive mobile inspection UI

The system SHALL provide a responsive, single-column inspection UI with type-specific inputs, photo upload, GPS capture, a progress indicator, navigation, and offline draft support.

**Spec ref**: REQ-VTH-002-B

#### Scenario: Field inspection on a mobile device

- **WHEN** an inspector opens the inspection view on a mobile device
- **THEN** each item SHALL render its type-specific input (checkbox/text/photo/GPS), a progress indicator SHALL show completion, and answers SHALL be retained offline and synced when connectivity returns

