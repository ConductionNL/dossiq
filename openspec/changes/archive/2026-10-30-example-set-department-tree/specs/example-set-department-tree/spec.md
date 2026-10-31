# example-set-department-tree

## ADDED Requirements

### Requirement: The Zuiddrecht departments are organisation roles with a group and a parent

The Zuiddrecht example set SHALL hold an `organisatieRol` for each department DqAfdelingen draws. Each SHALL name a Nextcloud group the set makes (`ncGroupId`) and its parent role (`parentRoleId`), with Gemeente Zuiddrecht at the top. The shipped Team Vergunningen and Team Handhaving roles SHALL be given their groups and Fysiek domein as their parent.

#### Scenario: The departments pane
- **GIVEN** the set is installed
- **WHEN** an administrator opens Departments and staff
- **THEN** Vergunningen SHALL be listed under Fysiek domein, and Jeugdteam under Sociaal domein
