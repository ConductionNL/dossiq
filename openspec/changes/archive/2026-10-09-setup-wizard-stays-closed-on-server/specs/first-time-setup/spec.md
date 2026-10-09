## ADDED Requirements

### Requirement: A closed setup wizard stays closed in every browser

The manifest SHALL declare `setup.dismissAction: "dismiss-setup"`. The
`dismiss-setup` setup action SHALL be admin-only and SHALL store the current
setup version under the app-config key `setup_dismissed_version`. It SHALL NOT
write any other key, so a demo-data choice or a dwangsom secret the
administrator set stays as it was. `GET /api/setup/status` SHALL report
`dismissed` as that stored version (an integer) once it is set, and SHALL leave
`dismissed` out before that.

#### Scenario: Closing the wizard records the setup version
@e2e exclude The close posts from CnAppRoot in the library; the action and the status payload are asserted by PHPUnit, the cross-browser result by the live check in tasks 4.2.

- **GIVEN** an administrator on an instance with the demo-data step open
- **WHEN** CnAppRoot posts `dismiss-setup` with `{ "finished": false }`
- **THEN** the action SHALL answer `success: true`
- **AND** `setup_dismissed_version` SHALL hold the setup version
- **AND** no other app-config key SHALL be written

#### Scenario: A real choice survives the close
@e2e exclude Same surface; asserted by PHPUnit.

- **GIVEN** an administrator who picked the dataset "demo" earlier
- **WHEN** the wizard is closed
- **THEN** `demo_dataset` SHALL still read "demo"

#### Scenario: Another browser does not reopen the wizard
@e2e exclude Needs two browser contexts against a mounted instance; done as the live check in tasks 4.2.

- **GIVEN** `setup_dismissed_version` holds 1 and the manifest `setup.version` is 1
- **WHEN** the status is read
- **THEN** it SHALL carry `dismissed: 1`
- **AND** CnAppRoot SHALL keep the wizard closed in a browser with empty storage

#### Scenario: No close, no dismissed key
@e2e exclude Same surface; asserted by PHPUnit.

- **GIVEN** an instance where nobody closed the wizard
- **WHEN** the status is read
- **THEN** it SHALL carry no `dismissed` key

### Requirement: The dwangsom secret step explains itself

The `dwangsom-secret` config-fields step SHALL carry a `body` that the wizard
draws as the step's intro, in English and Dutch.

#### Scenario: The intro shows above the field
@e2e exclude Rendering belongs to CnSetupWizard in the library; the live check in tasks 4.2 confirms it on dossiq.

- **GIVEN** the setup wizard on the dwangsom secret step
- **WHEN** the step renders
- **THEN** the intro text SHALL show above the secret field
