# woo-request-intake Delta: a-portal-filed-case-opens-like-a-staff-created-one

**Status**: draft
**Scope**: a case the system writes for a resident, through `SystemCaseStore`.

## ADDED Requirements

### Requirement: A case the system writes opens as a staff-created case does (REQ-WRI-005)

A case written by the system for a resident SHALL take every empty opening field from its case type (first status, handler, handling group, confidentiality, the moment it entered its first status), and SHALL be announced as created once, so every listener that answers a new case runs for it. A value the writer set SHALL be kept.

#### Scenario: A Woo request filed through the portal is acknowledged

- **GIVEN** the shipped Woo request case type
- **WHEN** a resident files a Woo request through the portal
- **THEN** the case is announced as created exactly once
- **AND** an acknowledgement of receipt is queued for it

#### Scenario: The filed case carries its case type's opening fields

- **GIVEN** a case type with handling group `behandelaars` and confidentiality `intern`
- **WHEN** a resident files a case of that type through the portal
- **THEN** the case's handling group is `behandelaars`, its confidentiality is `intern`, its status is the case type's first status, and it records when it entered that status
- **AND** a case type that names no handler leaves the case without one
