## OR Capability Citations

This spec consumes the following OpenRegister capabilities (per
ADR-022, procest-adopt-or-abstractions):

- `aggregations-backend-native` — count-by-status queries for the
  parafering dashboard are expressed as `x-openregister-aggregations`
  annotations on the case schema, not a custom dashboard service. See
  `openregister/openspec/changes/aggregations-backend-native/`.

## ADDED Requirements

### Requirement: Secretariaat Parafering Overview

@e2e exclude Parafering dashboard at /voorstellen is V1; the feature tier requires active voorstel case data and the secretariaat role which cannot be pre-seeded in automated e2e tests without a full parafering workflow.

The system SHALL provide a parafering dashboard at `/voorstellen` showing all active voorstellen with their current parafering status, intended for the secretariaat role.

**Feature tier**: V1

#### Scenario: Overview with active voorstellen

- **WHEN** the secretariaat views the parafering dashboard with 8 active voorstellen
- **THEN** each voorstel SHALL show: onderwerp, current step name, waiting actor, days in current step, overall progress (e.g., "stap 3/5")
- **AND** voorstellen overdue on any step (waiting > configured threshold) SHALL be highlighted with a warning indicator
- **AND** the list SHALL be sortable by: onderwerp, status, days waiting, steller

#### Scenario: Empty dashboard

- **WHEN** there are no active voorstellen
- **THEN** the dashboard SHALL display: "Geen actieve voorstellen"

### Requirement: Personal Parafering Inbox

@e2e exclude Personal parafering inbox is V1 and requires voorstellen assigned to the current user; data-dependent section not testable without pre-seeded parafering workflow data.

The system SHALL provide a personal parafering inbox showing voorstellen awaiting the current user's action. This SHALL be integrated into the MyWork view.

**Feature tier**: V1

#### Scenario: View personal inbox

- **WHEN** wethouder Van Dam has 3 voorstellen awaiting his parafering
- **AND** Van Dam opens the MyWork view
- **THEN** a "Ter parafering" section SHALL show the 3 voorstellen
- **AND** each SHALL display: onderwerp, case reference, steller, waiting since date
- **AND** each item SHALL be actionable directly (paraferen/terugsturen without opening full detail)

#### Scenario: No pending parafering

- **WHEN** the current user has no voorstellen awaiting their action
- **THEN** the "Ter parafering" section SHALL display: "Geen voorstellen ter parafering"

### Requirement: Send Parafering Reminder

@e2e exclude Sending parafering reminders is V1 and requires an overdue parafering step with a configured threshold and active voorstel; not testable without pre-seeded workflow data.

The system SHALL allow the secretariaat to send reminders to actors who have not yet acted on their parafering step.

**Feature tier**: V1

#### Scenario: Send reminder to overdue actor

- **WHEN** a voorstel has been waiting at step "Afdelingshoofd" for 5 days (above the threshold)
- **AND** the secretariaat clicks "Herinnering sturen"
- **THEN** a Nextcloud notification SHALL be sent to the afdelingshoofd: "Voorstel '[onderwerp]' wacht op uw parafering ([days] dagen)"
- **AND** the reminder SHALL be logged in the parafering audit trail

### Requirement: Voorstel List Navigation

@e2e exclude Voorstel list navigation is V1; the sidebar navigation item for Voorstellen is not yet implemented in the current build and navigating to /voorstellen shows an empty or unbuilt page.

The system SHALL add a "Voorstellen" navigation item to the Procest sidebar, linking to the parafering dashboard at `/voorstellen`.

**Feature tier**: V1

#### Scenario: Navigate to voorstellen

- **WHEN** any authenticated user clicks "Voorstellen" in the Procest sidebar
- **THEN** the system SHALL navigate to `/voorstellen`
- **AND** the list SHALL show voorstellen the user has access to (based on case access)
