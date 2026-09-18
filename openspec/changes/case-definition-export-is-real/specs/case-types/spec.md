## ADDED Requirements

### Requirement: An exported case type carries its own configuration (REQ-CT-40)

You move a case type between instances and it arrives whole.
`CaseDefinitionExportService::exportComponent()` SHALL read each requested
component from OpenRegister and SHALL NOT return a fixed empty shape. The
`schema` component carries the case type object and its property definitions,
`statuses` its status types and transitions, `permissions` its role types and
their group bindings, `documents` its document types and templates,
`metadata` its result types and decision types, and `workflows` the workflow
templates bound to it.

#### Scenario: A seeded case type exports its statuses
@e2e exclude Backend export service, covered by PHPUnit.

- **GIVEN** a case type with two status types and one transition between them
- **WHEN** the export runs with the `statuses` component
- **THEN** `statuses.json` SHALL list both status types by id and title
- **AND** it SHALL list the transition with its source and target status

#### Scenario: An unknown case type is refused
@e2e exclude Backend export service, covered by PHPUnit.

- **GIVEN** a case type id that no object answers to
- **WHEN** the export runs
- **THEN** the service SHALL throw
- **AND** no ZIP SHALL be written

### Requirement: The manifest names what the package contains (REQ-CT-41)

`buildManifest()` SHALL take `caseType.slug` and `caseType.title` from the
case type object rather than echoing the requested id, and SHALL fill
`dependencies` with every object ref the exported components point at, so an
importer can refuse a package whose references it cannot resolve.

#### Scenario: The manifest lists the workflow templates
@e2e exclude Backend export service, covered by PHPUnit.

- **GIVEN** a case type bound to one workflow template
- **WHEN** the export runs with every component
- **THEN** `manifest.json` `dependencies` SHALL contain that template's ref
- **AND** `manifest.json` `caseType.slug` SHALL be the object's slug

### Requirement: An import writes the objects or says it did not (REQ-CT-42)

`CaseDefinitionImportService::importComponent()` SHALL create or update the
OpenRegister objects of its component under the caller's `conflictResolution`
mode, and SHALL return the ids it created and the ids it replaced. A
component that writes nothing SHALL NOT report `status: 'success'`.
`importWorkflows()` SHALL deploy each workflow entry through the existing
workflow path, or SHALL return `status: 'error'` naming the entry it could
not deploy. Counting files SHALL NOT be reported as an import.

#### Scenario: An imported case type exists afterwards
@e2e exclude Backend import service, covered by PHPUnit.

- **GIVEN** an empty register and a package holding one case type with two
  statuses
- **WHEN** the import runs
- **THEN** the case type and both status types SHALL exist in the register
- **AND** the response SHALL name the three created ids

#### Scenario: A failed write is reported as an error
@e2e exclude Backend import service, covered by PHPUnit.

- **GIVEN** a package whose `statuses.json` references a case type that is not
  in the package
- **WHEN** the import runs
- **THEN** the `statuses` component SHALL report `status: 'error'`
- **AND** no status type SHALL have been created

### Requirement: A case type survives a round trip (REQ-CT-43)

Export then import SHALL reproduce the case type. A case type exported from
one register and imported into an empty register SHALL match the original on
every exported property, status type, transition, role binding, document type
and result type.

#### Scenario: Export and import reproduce the case type
@e2e exclude Backend round trip, covered by PHPUnit.

- **GIVEN** a case type with two statuses, one role, one document type and one
  workflow template
- **WHEN** it is exported and imported into an empty register
- **THEN** the imported case type SHALL match the original field by field
- **AND** its statuses, role, document type and workflow template SHALL match
