# case-management delta: finishing-picks-a-result

## ADDED Requirements

### Requirement: Finishing or aborting a case picks its result from the case type (REQ-CM-80)

The Finish and Abort acts SHALL offer the results a case of this case type can close with, including those a parent case type contributes, as a picker. The lifecycle dialog SHALL NOT ask for a result as free text. A close that names a result which is not one of the case type's results SHALL be refused with HTTP 422 and a sentence in the reader's language, before the case or a result row is written.

#### Scenario: Finish offers the case type's results
@e2e exclude {the picker is asserted in tests/vitest/finishingPicksAResult.spec.js and was checked on the rehearsal instance; no e2e fixture case type carries results}

- **GIVEN** a case whose case type (or its parent) declares the results "Toegewezen" and "Deels toegewezen"
- **WHEN** the handler opens Volgende stap and chooses Afronden
- **THEN** the form SHALL offer "Toegewezen" and "Deels toegewezen" in a picker
- **AND** the form SHALL NOT offer a free text field for the result

#### Scenario: An unknown result is refused, not failed
@e2e exclude {a server refusal asserted in tests/Unit/Service/Transitions/CaseResultWriterTest.php}

- **GIVEN** a case type with results
- **WHEN** a client posts finish with `resultTypeId` "Deels toegewezen"
- **THEN** the server SHALL answer 422 with error `result-type-unknown`
- **AND** the message SHALL be in the reader's language
- **AND** no result row SHALL be written
