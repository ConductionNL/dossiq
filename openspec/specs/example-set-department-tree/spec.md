# example-set-department-tree Specification

## Purpose
What dossiq does for example set department tree. The requirements were first written by the change `example-set-department-tree` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-department-tree/`) and cover: The Zuiddrecht departments are organisation roles with a group and a parent.

## Requirements

### Requirement: The Zuiddrecht departments are organisation roles with a group and a parent

The Zuiddrecht example set SHALL hold an `organisatieRol` for each department DqAfdelingen draws. Each SHALL name a Nextcloud group the set makes (`ncGroupId`) and its parent role (`parentRoleId`), with Gemeente Zuiddrecht at the top. The shipped Team Vergunningen and Team Handhaving roles SHALL be given their groups and Fysiek domein as their parent.

#### Scenario: The departments pane
- **GIVEN** the set is installed
- **WHEN** an administrator opens Departments and staff
- **THEN** Vergunningen SHALL be listed under Fysiek domein, and Jeugdteam under Sociaal domein
