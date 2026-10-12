# Decision tables

## ADDED Requirements

### Requirement: Dossiq's tables live in OpenRegister's decision-tables register (REQ-DTO-001)

The system SHALL store and read its decision tables in OpenRegister's `decision-tables` register under the key `dossiq.<key>` (lower case, OpenRegister's key pattern) with owner `dossiq`, and SHALL evaluate them through OpenRegister's `DecisionTableStore`. Without the store the system SHALL answer "no table", never an empty decision.

@e2e exclude unit only: no screen; StoredDecisionTablesTest validates every written row against OpenRegister's schema

#### Scenario: A free-text key

- GIVEN a dossiq table with key `LHS Matrix 2`
- WHEN it is stored
- THEN OpenRegister holds it as `dossiq.lhs-matrix-2`, owned by dossiq

### Requirement: The upgrade moves the stored tables (REQ-DTO-002)

On upgrade the system SHALL copy every enabled table from its own `decisionTable` schema into the register, SHALL keep a table already stored under its key, SHALL leave a disabled table in dossiq, and SHALL NOT delete the old rows. When the register cannot be read it SHALL move nothing and warn.

@e2e exclude repair step; MoveDecisionTablesToOpenRegisterTest

#### Scenario: Two runs

- GIVEN an enabled table `toets` and a disabled table `uit`
- WHEN the upgrade runs twice
- THEN `dossiq.toets` is stored once and `uit` stays in dossiq

### Requirement: A translated decision step names its table (REQ-DTO-003)

A retired `dossiq.evaluateDecision` step SHALL translate to an `openregister.decision-table` step with `tableRef` set to the table's stored key, and SHALL be refused when the table is not in the register.

@e2e exclude unit only; RetiredNodeTranslatorTest

#### Scenario: Decision step

- GIVEN a retired step naming `toets`
- WHEN it is translated
- THEN the step carries `tableRef: dossiq.toets` and no inline table

### Requirement: The LHS recommendation evaluates the stored table (REQ-DTO-004)

The LHS recommendation SHALL evaluate `dossiq.lhs-matrix-<version>` through the store and the simple LHS lookup SHALL evaluate `dossiq.lhs-intervention` first, each falling back as before when the store or the table is absent.

@e2e exclude unit only; LhsDecisionTableLookupTest, LhsLookupServiceTest

#### Scenario: Version 3

- GIVEN `dossiq.lhs-matrix-3` is stored
- WHEN a recommendation for matrix version 3 is asked
- THEN the intervention comes from that table
