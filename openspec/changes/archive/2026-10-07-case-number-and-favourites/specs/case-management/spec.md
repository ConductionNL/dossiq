# case-management

## MODIFIED Requirements

### Requirement: Every new case gets a number (REQ-CM-25)

Every new case gets a number like 2026-0042 without you typing it. The `case`
schema SHALL declare `identifier` as a generated identifier through
`x-openregister-generated`: sequence `case`, format `{year}-{seq:4}`,
`resetOn: year`, so OpenRegister takes the number under a lock inside the
create transaction. The field SHALL be read-only on every form and SHALL NOT
appear on the New case form. A case that already holds an identifier SHALL
keep it, and the counter SHALL advance past the number it holds. A change to
the number SHALL be refused, and the refusal SHALL read in OpenRegister's own
words.

The year SHALL be the year the case was filed. The retired
`x-openregister-calculations` expression read the start date instead; see
design D-2.

**Feature tier**: MVP

#### Scenario: A case filed from the form gets the next number
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** the last case of this year is numbered 2026-0041
- **WHEN** you file a case from New case on the Dashboard
- **THEN** the case page SHALL show the number 2026-0042
- **AND** the New case form SHALL NOT have offered a number field

#### Scenario: A case posted to the API gets a number too
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** a case posted to the case endpoint without an identifier
- **WHEN** the answer arrives
- **THEN** its identifier SHALL match `YYYY-NNNN`
- **AND** the year SHALL be the year it was filed

#### Scenario: An existing number stays
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** a case that holds the identifier BZW-2025-17
- **WHEN** you edit and save its title
- **THEN** its identifier SHALL still be BZW-2025-17

#### Scenario: Two cases filed at once get two numbers
@e2e exclude {the lock lives in OpenRegister's SequenceService and is proven by its own concurrency test; dossiq declares the annotation and can only assert that}

- **GIVEN** the `case` schema declaring sequence `case`
- **WHEN** two cases are filed in the same second
- **THEN** they SHALL hold two different numbers

#### Scenario: A number you supply is kept and pushes the counter on
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case imported with the identifier 2026-0120
- **WHEN** the next case is filed without one
- **THEN** the imported case SHALL still read 2026-0120
- **AND** the new case SHALL NOT be given a number below it

#### Scenario: Changing the number is refused in OpenRegister's words
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case numbered 2026-0042
- **WHEN** you send an update that changes the identifier to 2026-0099
- **THEN** the write SHALL be refused
- **AND** the message SHALL name the number that was issued
- **AND** the case SHALL still read 2026-0042

## ADDED Requirements

### Requirement: A complaint number comes from the same counter mechanism (REQ-CNUM-01)

A klachtnummer SHALL be issued by the platform and not counted in dossiq. The
`complaint` schema SHALL declare `complaintNumber` through
`x-openregister-generated` with its own sequence `complaint`, format
`KL-{year}-{seq:4}` and `resetOn: year`. `ComplaintService` SHALL NOT compute
a number, and no dossiq class SHALL count existing rows to produce one.

**Feature tier**: MVP

#### Scenario: A complaint is filed and carries a KL number
@e2e exclude {the complaint intake surface has no page of its own yet; the schema declaration is asserted in tests/Unit/Settings/CaseIdentitySchemaTest.php and the service in tests/Unit/Service/ComplaintServiceTest.php}

- **GIVEN** a complaint filed through `ComplaintService`
- **WHEN** it is saved
- **THEN** its complaintNumber SHALL match `KL-YYYY-NNNN`

#### Scenario: Deleting a complaint does not hand its number out again
@e2e exclude {a deletion and a refile inside one run needs two writes against a shared counter; asserted in tests/Unit/Service/ComplaintServiceTest.php against the retired counter}

- **GIVEN** this year's last complaint is KL-2026-0007
- **WHEN** it is deleted and another complaint is filed
- **THEN** the new complaint SHALL NOT be numbered KL-2026-0007

### Requirement: You star a case and it stays starred for you alone (REQ-FAV-01)

You SHALL be able to star a case from its page and from a row in any case
list, and unstar it the same way. The star SHALL be written through
OpenRegister's favourite endpoint, SHALL be yours alone, and SHALL leave the
case itself untouched: no new version, no audit entry, no change anybody else
sees. dossiq SHALL store no favourite of its own.

**Feature tier**: MVP

#### Scenario: You star a case from its page
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case you have not starred
- **WHEN** you press the star on the case page
- **THEN** the star SHALL read as set after a reload

#### Scenario: Starring changes nothing on the case
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case with a known number of audit entries
- **WHEN** you star it and read it back
- **THEN** the case SHALL carry the same number of audit entries

#### Scenario: You star a case from a list row
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** the Cases list
- **WHEN** you use Add to favourites on a row
- **THEN** that case SHALL appear under the Favourites chip

### Requirement: Favourites and recently opened are lenses and tiles (REQ-FAV-02)

The Cases index SHALL offer a Favourites chip and a Recently opened chip, each
narrowing the list through OpenRegister's own lens rather than a dossiq query.
The Dashboard SHALL carry a Favourites tile and a Recently opened tile over
the same two lenses, each linking through to the matching chip.

**Feature tier**: MVP

#### Scenario: The Favourites chip lists only what you starred
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** two of five cases starred by you
- **WHEN** you pick the Favourites chip on Cases
- **THEN** the list SHALL hold those two cases and no others

#### Scenario: The Recently opened chip leads with the last case you read
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** three cases you opened, the last of them case C
- **WHEN** you pick the Recently opened chip on Cases
- **THEN** case C SHALL be the first row

#### Scenario: The dashboard tiles show the same two lists
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a starred case and a case you opened
- **WHEN** you open the Dashboard
- **THEN** the Favourites tile SHALL name the starred case
- **AND** the Recently opened tile SHALL name the case you opened
