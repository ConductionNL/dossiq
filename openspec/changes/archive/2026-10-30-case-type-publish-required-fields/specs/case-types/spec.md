## ADDED Requirements

### Requirement: A case type states its service target and notification domain (REQ-CT-SVC-1)

A case type MUST be able to carry a service target, an ISO 8601 duration the
organisation aims to finish a case within. The case type page MUST show the
service target with the terms and the notification domain with the case type,
and the create form MUST ask both.

#### Scenario: set a service target at creation
- GIVEN the Case types page
- WHEN an administrator creates a case type with service target P42D and notification domain Zaken
- THEN the case type page shows P42D under Terms and Zaken under Case type
