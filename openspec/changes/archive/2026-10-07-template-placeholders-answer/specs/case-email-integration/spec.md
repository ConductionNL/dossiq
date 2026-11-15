## ADDED Requirements

### Requirement: Every placeholder a shipped template names is answered (REQ-MAIL-20)

Every `{{placeholder}}` in every template dossiq ships — in
`EmailTemplateService::DEFAULT_TEMPLATES` and in every `emailTemplate` seeded by
a register fragment — SHALL be a key `EmailTemplateService::buildVariableMap()`
returns for a case.

The check SHALL derive both sets from source: the placeholders by reading the
templates, and the answerable keys by calling the map. A list of expected names
written into the test is a third copy of the same knowledge and would have
drifted with the other two.

The check SHALL ask `collectUnresolved()` rather than re-implement the match, so
it cannot disagree with the renderer it protects.

The deprecated aliases of REQ-MAIL-21 SHALL be SUBTRACTED from the answerable
set before a shipped template is checked. Without that subtraction the check
passes on a shipped body carrying `{{behandelaar}}`, which is to say it would
wave the original defect straight through: measured, reintroducing it reddens
nothing until the aliases are removed from the comparison.

#### Scenario: A shipped template names only answerable keys
@e2e exclude structural; asserted in `tests/Unit/Service/TemplatePlaceholdersTest.php` over every shipped template and the live map

- **GIVEN** every template dossiq ships
- **WHEN** its placeholders are compared with the keys the variable map answers
- **THEN** every placeholder SHALL be answerable
- **AND** a template that names an unanswerable key SHALL fail, naming the template and the key

#### Scenario: The gate is shown able to fail
@e2e exclude structural; a template carrying a deliberately unknown name is asserted to be reported by `collectUnresolved()`

- **GIVEN** a template naming a placeholder the map does not answer
- **WHEN** the check runs
- **THEN** it SHALL report that placeholder

### Requirement: The map answers the pre-rename names (REQ-MAIL-21)

`buildVariableMap()` SHALL answer `startDatum`, `einddatum` and `behandelaar`
with the same values as `startDate`, `endDate` and `handler`.

These are the names the shipped templates carried from 2026-06-11, and they are
in the bodies administrators have been editing since. An instance that upgrades
SHALL see its stored templates work again without anyone editing them, because
a migration that rewrote a stored body would be editing somebody's letter.

The catalogue returned by `getAvailableVariables()` SHALL list only the
canonical names. The aliases are a door out of the past, not a second
vocabulary: a template authored after this change SHALL be offered `handler` and
never `behandelaar`.

#### Scenario: A template stored before the rename renders again
@e2e exclude unit; `tests/Unit/Service/TemplatePlaceholdersTest.php::testTheMapAnswersThePreRenameNames`

- **GIVEN** a stored template body carrying `{{behandelaar}}`
- **WHEN** it is rendered against a case with an assignee
- **THEN** the assignee's name SHALL appear
- **AND** no `{{` SHALL remain in the rendered text

#### Scenario: The editor offers the canonical name only
@e2e exclude unit; same file

- **WHEN** the variable catalogue is read
- **THEN** it SHALL contain `handler`
- **AND** it SHALL NOT contain `behandelaar`
