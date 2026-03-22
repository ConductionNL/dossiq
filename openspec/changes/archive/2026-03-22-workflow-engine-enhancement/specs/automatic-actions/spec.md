## ADDED Requirements

### Requirement: Automatic Action Framework

The system SHALL support configurable automatic actions that execute when a status transition occurs or a step is completed. Actions are defined as part of the workflow template and executed by the frontend or delegated to n8n webhooks.

**Feature tier**: V1

Action types:
- `sendEmail`: Send a templated email to a case participant
- `createTask`: Create a new task for a specified role
- `createSubCase`: Create a deelzaak of a specified type
- `webhook`: Call an n8n webhook URL with case data
- `setField`: Set a case property to a computed value
- `notify`: Create a Nextcloud notification for a user or role

#### Scenario: Configure email action on transition

- **WHEN** an administrator configures transition "Afhandelen" with action `sendEmail`
- **THEN** the configuration panel SHALL allow setting: recipient (role or specific field), email template, subject template
- **AND** templates SHALL support variable substitution: `{{case.title}}`, `{{case.handler}}`, `{{transition.label}}`

#### Scenario: Email action executes on transition

- **WHEN** transition "Afhandelen" is executed on case "ZK-2024-001"
- **AND** the transition has a `sendEmail` action configured for the "zaakklant" role
- **THEN** the system SHALL send the templated email to the email address of the user with role "zaakklant" on the case

#### Scenario: Task creation action on step completion

- **WHEN** step "Toets ontvankelijkheid" is completed
- **AND** the step has a `createTask` action configured for role "Vakspecialist"
- **THEN** the system SHALL create a new task with the configured title and description, assigned to role "Vakspecialist"

#### Scenario: Webhook action delegates to n8n

- **WHEN** a transition has a `webhook` action configured with URL `https://n8n.example.com/webhook/abc123`
- **AND** the transition is executed
- **THEN** the system SHALL POST the case data (id, title, status, transition details) to the webhook URL
- **AND** the webhook response SHALL be logged but SHALL NOT block the transition

### Requirement: Action Execution Error Handling

The system SHALL handle action execution failures gracefully without rolling back the transition.

**Feature tier**: V1

#### Scenario: Email action fails

- **WHEN** a `sendEmail` action fails (SMTP error, invalid recipient)
- **THEN** the status transition SHALL still complete successfully
- **AND** the error SHALL be logged in the case audit trail
- **AND** a warning notification SHALL be created for the case handler: "Automatische e-mail kon niet worden verzonden"

#### Scenario: Webhook action times out

- **WHEN** a `webhook` action does not respond within 10 seconds
- **THEN** the action SHALL be marked as failed in the audit trail
- **AND** the transition SHALL still complete successfully
