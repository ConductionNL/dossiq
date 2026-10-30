# enforcement-lhs delta: lhs-packages

## ADDED Requirements

### Requirement: The default LHS matrix ships as a case definition package

The app SHALL ship the default matrix of the Landelijke Handhavingsstrategie and the 16 cells of its behaviour by consequence grid as the case definition package `lhs-matrix`, `install: auto`. The installer SHALL recognise an existing matrix by `active` being true, whatever its name, and SHALL NOT write a second active matrix. The installer SHALL recognise an existing grid cell by its `behaviourRow` and `consequenceColumn` together, and SHALL leave that cell as it is.

#### Scenario: A clean install has one active matrix
@e2e tests/e2e/packages/lhs-matrix.spec.ts

- **GIVEN** a clean instance after install
- **WHEN** an enforcement officer reads the matrices
- **THEN** exactly one matrix SHALL be active
- **AND** it SHALL hold cells

#### Scenario: An instance with its own active matrix gets no second one
@e2e tests/e2e/packages/lhs-matrix.spec.ts

- **GIVEN** an instance whose administrator replaced the default matrix with an active matrix under another name
- **WHEN** the upgrade installs the `lhs-matrix` package
- **THEN** exactly one matrix SHALL be active
- **AND** it SHALL be the administrator's

#### Scenario: The grid has one cell for each row and column
@e2e tests/e2e/packages/lhs-matrix.spec.ts

- **GIVEN** an instance with the `lhs-matrix` package installed
- **WHEN** an enforcement officer reads the grid
- **THEN** each of the rows A to D and the columns 1 to 4 SHALL have exactly one cell
- **AND** the lookup for a row and a column SHALL name an intervention

### Requirement: The LHS intervention table ships as a case definition package

The app SHALL ship the intervention table of the Landelijke Handhavingsstrategie as the case definition package `lhs-intervention`, `install: auto`. The package SHALL write the table into OpenRegister's `decision-tables` register under the key `dossiq.lhs-intervention`. The shipped table SHALL answer, for each of the 16 positions, the `interventionStep` and the `description` of the shipped grid cell at that position, so that a lookup that reads the table gives the recommendation the grid gave. The installer SHALL recognise an existing table by that key and SHALL leave it as it is. On an OpenRegister that does not hold the `decision-tables` register the installer SHALL refuse the package, name `openregister:decision-table`, and write nothing.

#### Scenario: The intervention table is in OpenRegister under its key
@e2e tests/e2e/packages/lhs-intervention.spec.ts

- **GIVEN** an instance with the `lhs-intervention` package installed
- **WHEN** an administrator looks up the decision table with key `dossiq.lhs-intervention` in OpenRegister
- **THEN** exactly one table SHALL exist
- **AND** it SHALL hold one rule for each of the rows A to D and the columns 1 to 4

#### Scenario: The table answers what the grid cell says
@e2e exclude no user interface reads the table until the lookup does; proven for all 16 positions by tests/Unit/Settings/LhsInterventionTableMatchesGridTest.php with OpenRegister's evaluator, and on a clean instance by the Newman collection tests/integration/packages/lhs-intervention.postman_collection.json

- **GIVEN** the shipped `lhs-matrix` and `lhs-intervention` packages
- **WHEN** the intervention table is evaluated for a behaviour row and a consequence column
- **THEN** it SHALL answer the `interventionStep` of the grid cell for that row and column
- **AND** it SHALL answer that cell's `description`

#### Scenario: OpenRegister has no decision-tables register
@e2e exclude no user interface; the refusal for a schema a register does not hold is proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testASeedForASchemaTheRegisterDoesNotHoldRefusesThePackage

- **GIVEN** an OpenRegister that does not ship the `decision-tables` register
- **WHEN** the installer installs the `lhs-intervention` package
- **THEN** the outcome SHALL be refused, naming `openregister:decision-table`
- **AND** no row SHALL be written
