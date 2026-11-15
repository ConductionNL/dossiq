## ADDED Requirements

### Requirement: A translatable case type title or status name is read as text (REQ-CT-46)

Wherever dossiq reads a case type's `title`, or the `name` of a status,
result or role type, as text, it SHALL read a language map in the reader's
language when one is known, then Dutch, then the first text in the map. It
SHALL NOT show, store or log the string "Array" for such a value. The
workflow backfill SHALL name a basis template "{case type title} (basis)",
and SHALL heal a basis template it stored with "Array" in its title, step
titles, step descriptions or move labels, keeping every step and move id and
leaving any template it did not write untouched.

#### Scenario: a refusal names the statuses, not Array
@e2e exclude a save refusal on the case type import path with no screen of its own; covered in tests/Unit/Listener/CaseTypePortalWithdrawalListenerTest.php::testStatusNamesStoredAsLanguageMapsAreNamedInTheRefusal

- **GIVEN** a case type whose status names are stored as language maps
- **WHEN** its withdrawal names a status no move reaches
- **THEN** the refusal SHALL read "A case in Ontvangen cannot move to Ingetrokken"
- **AND** it SHALL NOT contain "Array"

#### Scenario: a new basis template is named in text
@e2e exclude a repair step with no screen; covered in tests/Unit/Repair/MigrateWorkflowDefinitionsLabelsTest.php::testANewBasisTemplateIsNamedInText

- **GIVEN** a Woo case type whose title and status names are language maps
- **WHEN** the workflow backfill builds its basis template
- **THEN** the template SHALL be titled "Woo-verzoek (basis)"
- **AND** each move SHALL be labelled with its target status name

#### Scenario: a basis template stored with Array labels is healed
@e2e exclude a repair step with no screen; the lifecycle dialog's buttons read these labels, checked live on review-check; covered in tests/Unit/Repair/MigrateWorkflowDefinitionsLabelsTest.php::testABasisTemplateStoredWithArrayLabelsIsHealed and testATemplateTheBackfillDidNotWriteIsLeftAlone

- **GIVEN** a basis template stored as "Array — basis" with steps and moves labelled "Array"
- **WHEN** the workflow backfill runs again
- **THEN** the template SHALL be titled "Woo-verzoek (basis)"
- **AND** its steps and moves SHALL carry the status names, with their ids unchanged
- **AND** a template the backfill did not write SHALL NOT be changed
