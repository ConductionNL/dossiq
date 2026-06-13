# Spec delta: vth-workflow-templates

## ADDED Requirements

### Requirement: VTH workflow template activation service

The system SHALL provide a `VTHWorkflowService` that loads and activates the three VTH workflow templates declared by the config-foundation member, creating each template's statuses and roles, and SHALL be idempotent on re-activation.

**Spec ref**: REQ-VTH-001, REQ-VTH-002, REQ-VTH-003

#### Scenario: Activate Omgevingsvergunning template

- **WHEN** an administrator activates the Omgevingsvergunning workflow template
- **THEN** the service SHALL create the template's statuses (Aanvraag ontvangen … Afgehandeld) and roles (Vergunningverlener, Juridisch adviseur, Administratief medewerker)

#### Scenario: Activate Toezichtzaak and Handhavingszaak templates

- **WHEN** an administrator activates the Toezichtzaak or Handhavingszaak template
- **THEN** the service SHALL create the corresponding statuses and roles defined in the respective template JSON

#### Scenario: Idempotent re-activation

- **WHEN** a template that has already been activated is activated again
- **THEN** the service SHALL NOT create duplicate statuses or roles
