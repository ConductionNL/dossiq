---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# vth-admin-settings Specification

## Purpose
Provides administrators a single VTH configuration page with tabs for Workflows, Leges Rules, Beschikking Templates, Inspection Checklists, and DSO Settings. Administrators can manage the three VTH workflows, build versioned inspection checklists with reorderable typed items and a mobile preview, and configure validated DSO integration settings persisted via the SettingsService.
## Requirements
### Requirement: VTH configuration settings page

The system SHALL provide an admin VTH configuration page with tabs for Workflows, Leges Rules, Beschikking Templates, Inspection Checklists, and DSO Settings.

**Spec ref**: REQ-VTH-008, REQ-VTH-008-A

#### Scenario: Workflow management tab

- **WHEN** an administrator opens the Workflows tab
- **THEN** the three VTH workflows SHALL be listed with version, active status, and activate/deactivate, view (diagram), and download (JSON) actions

### Requirement: Inspection checklist configuration

The system SHALL provide an admin tab to create and edit inspection checklists by case type, with reorderable typed items and versioned saves.

**Spec ref**: REQ-VTH-002-C, REQ-VTH-008-D

#### Scenario: Configure a checklist

- **WHEN** an administrator adds items (question, type, required flag, help text), reorders them, and saves
- **THEN** a new versioned checklist SHALL be created and a mobile preview SHALL be available

### Requirement: DSO settings

The system SHALL provide an admin tab to configure DSO integration settings, persisted via SettingsService.

**Spec ref**: REQ-VTH-008

#### Scenario: Save DSO settings

- **WHEN** an administrator sets the enable flag, OpenConnector endpoint, deadline warning thresholds, and beschikking-template selections and saves
- **THEN** the settings SHALL be validated (numbers ≥ 0, endpoint a valid URL) and persisted via SettingsService

