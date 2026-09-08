## ADDED Requirements

### Requirement: REQ-005: A library template is offered on the case

You pick a template where you need it. The template picker of the Generate
document action on `CaseDetail` SHALL list the templates that
`TemplateController#index` returns, by name, and SHALL pass the chosen
template's slug to `DossiqMergeTemplateNode` as `templateSlug`. A template
that names a `documentType` SHALL have that type applied to the generated
`informatieobject`. The library stays the single source; the action does not
copy templates.

#### Scenario: The picker lists the library
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** a library with the templates Ontvangstbevestiging and Verdagingsbrief
- **WHEN** you press Generate document on a case
- **THEN** the picker SHALL list both by name

#### Scenario: The template's document type follows into the dossier
@e2e exclude The handler's type mapping is a PHPUnit test over MergeTemplateHandler with a stubbed template.

- **GIVEN** a template with `documentType` set to an informatieobjecttype
- **WHEN** the node runs it for a case
- **THEN** the created informatieobject SHALL carry that type
