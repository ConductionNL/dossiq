## ADDED Requirements

### Requirement: Generate a document from the case (REQ-BES-012)

You generate a letter without leaving the case. `CaseDetail` SHALL carry a
header action Generate document that runs `DossiqMergeTemplateNode` with the
case as subject and a template picked from the library. When the node's
config names no `targetField`, `MergeTemplateHandler` SHALL store the
rendered result as an `informatieobject` with status draft, direction
outgoing, the signed-in user as author and the template name as title, and
SHALL link it to the case through a `zaakinformatieobject`. On success the
Documents tab SHALL refresh and show the new row. When `targetField` is
present the handler SHALL keep writing into the case field, so existing
flows do not change.

#### Scenario: Generate a letter onto the Documents tab
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** an open case and a library template Ontvangstbevestiging
- **WHEN** you press Generate document, pick Ontvangstbevestiging and confirm
- **THEN** the Documents tab SHALL show a draft row titled Ontvangstbevestiging with direction Outgoing
- **AND** the row's author SHALL be you

#### Scenario: A targetField keeps the old behaviour
@e2e exclude The two handler branches are a PHPUnit test over MergeTemplateHandler; no page exercises targetField.

- **GIVEN** a node config with `templateSlug` and `targetField` set to `motivation`
- **WHEN** the handler runs for a case
- **THEN** the case field `motivation` SHALL hold the rendered text
- **AND** no informatieobject SHALL be created

#### Scenario: A failed render leaves the dossier untouched
@e2e exclude Rendering failure is a PHPUnit test with a template that references a missing field.

- **GIVEN** a template that references a field the case does not have
- **WHEN** the handler runs for the case
- **THEN** it SHALL return a failed ActionResult with the field name
- **AND** no informatieobject and no zaakinformatieobject SHALL be created
