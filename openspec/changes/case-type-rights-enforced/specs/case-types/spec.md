# case-types Delta

## ADDED Requirements

### Requirement: A rights row grants to the caller's own team

A case type rights row SHALL accept department `$ownTeam`, granting its actions on the cases assigned to a team the caller is a member of, resolved when access is checked.

#### Scenario: an intaker sees only their own team's cases

- **GIVEN** case type Omgevingsvergunning regulier with a row Intaker, `$ownTeam`, read
- **AND** user A, an Intaker in team Vergunningen
- **WHEN** A lists cases
- **THEN** A sees the Vergunningen cases of that type and none of team Toezicht
- @e2e exclude {access is enforced in OpenRegister; covered by PHPUnit with the real permission handler}

### Requirement: A rights row can be limited to statuses

A rights row SHALL accept `statuses`. Its edit verbs SHALL apply only while the case is in one of them; its read verbs SHALL apply in every status.

#### Scenario: an intaker edits in the first phase only

- **GIVEN** a row Intaker, `$ownTeam`, read and update, statuses [Ontvangen]
- **WHEN** A edits a case in Ontvangen and one in In behandeling
- **THEN** the first edit succeeds and the second is refused
- **AND** A still reads the case in In behandeling
- @e2e exclude {covered by PHPUnit with the real permission handler}

### Requirement: Documents have their own rights per role

A rights row SHALL accept `documentActions` (`read`, `add`, `delete`), enforced on the case's files.

#### Scenario: an inspector reads documents and adds none

- **GIVEN** a row Toezichthouder, `*`, read, documents read
- **WHEN** an inspector lists a case's files and then uploads one
- **THEN** the files are listed and the upload is refused
- @e2e exclude {enforced in OpenRegister's file access; covered by PHPUnit through its FilesController}

### Requirement: The Overview reads decisions from the mandate register

The Overview's Rights card SHALL show per role the decision right from the active mandates for this case type: the highest ceiling as "Tot € <amount>", "Onbeperkt" without a ceiling, "Nee" without a mandate. The tenant setup checklist SHALL mark "Mandaten ingesteld" when the organisation has an active mandate.

#### Scenario: the permit case type's Rights card

- **GIVEN** the rows above and an active mandate for Vergunningverlener with ceiling 5,000,000 cents for this case type
- **THEN** the card reads Intaker · Eigen team · Fase 1 · Nee · Toevoegen; Vergunningverlener · Alle · Alle fasen · Tot € 50.000 · Alles; Toezichthouder · Alle · Nee · Nee · Lezen
- @e2e exclude {projection covered by PHPUnit and the card by vitest; the board pixel pass checks the drawn card}
