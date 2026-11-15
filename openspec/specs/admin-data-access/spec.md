# admin-data-access Specification

## Purpose
What dossiq does for admin data access. The requirements were first written by the change `privacy-and-social-domain-groups` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-privacy-and-social-domain-groups/`) and cover: Privacy officers MUST read and handle data breaches; Social-domain staff MUST own the social-domain records; The role groups MUST be provisioned.

## Requirements

### Requirement: Privacy officers MUST read and handle data breaches

A user in `dossiq-privacy` SHALL be able to read and update avgIncident objects. Admin and beheerders keep that access. Every member of staff SHALL still be able to create one. The data breach register and the Privacy page's Data breaches tab SHALL be offered only to users holding the `privacy` role permission.

#### Scenario: A privacy officer opens the Privacy page

- **GIVEN** a user in `dossiq-privacy`
- **WHEN** they open Privacy
- **THEN** the Data breaches tab shows, and the register lists the breaches

#### Scenario: A member of staff opens the Privacy page

- **GIVEN** a user in `dossiq-medewerkers` only
- **WHEN** they open Privacy
- **THEN** the Data breaches tab does not show
- **AND** OpenRegister refuses their read of avgIncident

### Requirement: Social-domain staff MUST own the social-domain records

A user in `dossiq-sociaal-domein` SHALL be able to read, create and update indicatiestelling, mdoOverleg and reIntegratieTraject objects. Admin and beheerders keep that access, and the background service account keeps its access. Other staff SHALL NOT read or write them. The Indications, Case meetings and Re-integration trajectories pages SHALL be offered only to users holding the `sociaal-domein` role permission.

#### Scenario: A member of staff outside the group reads an indication

- **GIVEN** a user in `dossiq-medewerkers` only
- **WHEN** they request indicatiestelling objects
- **THEN** OpenRegister returns none

### Requirement: The role groups MUST be provisioned

`dossiq-privacy` and `dossiq-sociaal-domein` SHALL be created by the repair step that creates dossiq's other groups, so the authorization names groups the server has.

#### Scenario: Install

- **WHEN** dossiq's repair steps run on an instance without the groups
- **THEN** both groups exist, seeded with the administrators
