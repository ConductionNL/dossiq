# dossiq-sociaal-domein-participatiewet (delta)

## ADDED Requirements

### Requirement: A re-integration trajectory is a case of the Re-integratietraject case type (REQ-PW-RI-001)

dossiq SHALL ship the case type `re-integratietraject` as an automatically installed package,
handled by the group `dossiq-sociaal-domein`, confidential, with the statuses Aangemeld, Plan van
aanpak, In uitvoering, Evaluatie, Afgerond and Beëindigd, and the trajectory's data as its fields.
A trajectory SHALL NOT move to In uitvoering before its kind and its distance to the labour market
are answered, and SHALL NOT close as Afgerond without a result.

#### Scenario: The case type carries the trajectory's data
- **GIVEN** the shipped package
- **THEN** every field the trajectory record held SHALL be a case field or a field of the case type
- **AND** the exemption fields SHALL be hidden on the create form

### Requirement: Stored trajectories move onto cases without loss (REQ-PW-RI-002)

On upgrade, every stored `reIntegratieTraject` SHALL become exactly one case of the type, in In
uitvoering, with its klantmanager as assignee, its start date, its uuid as `externalReference`
and every other field answered on the case. The trajectory SHALL be left as it was apart from
`migratedTo` and `migratedAt`.

#### Scenario: A shipped trajectory maps onto a case
- **GIVEN** a trajectory with instruments, parties, evaluation moments and an exemption
- **WHEN** the case type's import maps it
- **THEN** the case SHALL carry each value, lists as JSON, the exemption as three answers

### Requirement: Re-integration trajectories are a view of the case list (REQ-PW-RI-003)

The Cases list SHALL offer the default view `re-integration-pathways`, the cases of the type
`re-integratietraject`.

#### Scenario: The view lists the trajectories
- **WHEN** a user opens `/cases?savedView=default:re-integration-pathways`
- **THEN** the list SHALL show the cases of the type, newest start first

### Requirement: The package ships an example trajectory (REQ-PW-RI-004)

On an instance that opted into example data, the package SHALL write one trajectory in flight as
a case of the type, answering only the type's fields and tied to the shipped Participatiewet case
`zaak-2026-pw-01278`. The demo data SHALL NOT carry placeholder `reIntegratieTraject` rows.

#### Scenario: The example reads as a real trajectory
- **GIVEN** an instance with example data
- **WHEN** the package installs
- **THEN** one case of the type SHALL be In uitvoering with its klantmanager, instruments and benefit case
