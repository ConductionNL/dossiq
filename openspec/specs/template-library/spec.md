---
status: done
retrofit: true
---

# Template Library Specification

## Purpose

@e2e exclude Template library scenarios cover backend REST endpoints and file loading; activation is a backend service test, not Playwright.

Ship a library of zaaktype templates (JSON files bundled with the app) and provide a REST surface to discover, inspect, and activate them. Activation expands a template into a fully-linked set of OpenRegister objects (caseType + statusTypes + propertyDefinitions + documentTypes + decisionTypes + roleTypes) so an administrator can stand up a working case type from a single click.

## Requirements

### REQ-001: Template REST endpoints (index / show / activate)

The system SHALL expose three `@NoAdminRequired` JSON endpoints on `TemplateController`: `index()` returns `{results: [...]}` with the template list, `show(id)` returns the full template payload or HTTP 404 `{error: 'Template not found'}`, and `activate(id)` returns the activation result or HTTP 400 `{error: <message>}` on `\RuntimeException`.

#### Scenario: Not found

- WHEN `show(id)` is called with an id that no template advertises
- THEN the controller SHALL return HTTP 404 `{error: 'Template not found'}`

#### Scenario: Activation failure wrapping

- WHEN `activate(id)` raises `\RuntimeException`
- THEN the controller SHALL return HTTP 400 `{error: <exception message>}`

### REQ-002: Template discovery from filesystem JSON files

The system SHALL discover templates by scanning `lib/Settings/templates/*.json` (relative to `TemplateLibraryService`), parsing each as JSON, and emitting metadata rows of `{id, title, description, category, version, file}` for every valid file.

#### Scenario: Missing directory

- WHEN `lib/Settings/templates/` does not exist
- THEN `listTemplates()` SHALL return an empty array (not an error)

#### Scenario: Invalid JSON

- WHEN a `*.json` file fails to parse or is not an object
- THEN the service SHALL log a warning `'Invalid template file: <basename>'` and skip the file (other files still surface)

#### Scenario: id fallback

- WHEN a template JSON lacks an `id` field
- THEN the service SHALL fall back to the file's basename (without `.json`)

#### Scenario: Default values

- WHEN a template JSON omits `category` or `version`
- THEN they SHALL default to `'general'` and `'1.0.0'` respectively

### REQ-003: Template lookup by id with filename-fallback

The system SHALL match `loadTemplate(templateId)` against either the JSON's explicit `id` field or — when absent — the file's basename, returning the full parsed payload on match or `null` on miss.

#### Scenario: Match against explicit id

- WHEN a template file declares `"id": "vth-omgevingsvergunning"`
- THEN `loadTemplate('vth-omgevingsvergunning')` SHALL return its full parsed payload

#### Scenario: Match against filename fallback

- WHEN a file `omgevingsplan.json` has no explicit `id`
- THEN `loadTemplate('omgevingsplan')` SHALL return its parsed payload

#### Scenario: No match

- WHEN no file matches by either id or basename
- THEN the service SHALL return `null`

### REQ-004: Template activation expands JSON into linked OpenRegister objects

The system SHALL activate a template by creating one `caseType` object, then iterating the template's `statusTypes`, `propertyDefinitions`, `documentTypes`, `decisionTypes`, and `roleTypes` collections — each child object SHALL have its `caseType` foreign key set to the newly-created case-type UUID before persistence. The result SHALL be `{templateId, caseType: <uuid>, statuses: [uuid...], properties: [uuid...], documents: [uuid...], decisions: [uuid...], roles: [uuid...]}`.

#### Scenario: Template not found

- WHEN `activateTemplate(<unknown id>)` is called
- THEN it SHALL throw `\RuntimeException('Template not found: <id>')`

#### Scenario: OpenRegister or register guards

- WHEN OpenRegister is unavailable
- THEN the service SHALL throw `\RuntimeException('OpenRegister is not available')`
- AND when `register` config is unset it SHALL throw `\RuntimeException('Dossiq register not configured')`

#### Scenario: Child objects linked to caseType

- WHEN activation processes any of the five child collections
- THEN every child payload SHALL be mutated to set `caseType = <new caseTypeId>` before `saveObject`

#### Scenario: Empty collections

- WHEN a template omits one of the child collections (e.g. `decisionTypes`)
- THEN activation SHALL treat it as `[]` and the corresponding result array SHALL be empty

#### Notes

- Activation is **not transactional** — partial failure leaves the case type and any already-created children persisted with the rest missing. Future hardening should wrap in a transaction or compensating rollback.

### Requirement: The result template presets the text on the form a handler can reach (REQ-TPL-06)

You close a case with a standard result and the standard words are already
there. `CaseLifecycleMenuDialog` SHALL offer result templates scoped to the
case type on every act that carries a result, and choosing one SHALL preset
the outcome text. The template SHALL be offered on the surface that closes a
case, not on a component no manifest action opens.

#### Scenario: a standard refusal is not retyped
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a result template for a niet-ontvankelijkverklaring, scoped to
  bezwaar case types
- **WHEN** a handler closes a bezwaar with that result and picks the template
- **THEN** the outcome text SHALL be preset from the template body

#### Scenario: a template for other case types is not offered
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a result template scoped to bezwaar case types
- **WHEN** a handler closes a vergunning case
- **THEN** that template SHALL NOT be offered

### Requirement: A template never overwrites what the handler wrote (REQ-TPL-07)

Picking a template SHALL NOT replace text the handler has already typed. A
handler who has written two paragraphs and then picks a template SHALL keep
their paragraphs.

This is the assertion that matters and it is stated as its own requirement so
it cannot be dropped as a detail of the one above. Losing typed text to a
convenience is worse than having no templates at all: the handler cannot get
it back, and the gesture that destroyed it looked like help.

#### Scenario: typed text survives a template
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a handler who has typed an outcome text on the close form
- **WHEN** they pick a result template
- **THEN** the text they typed SHALL still be there

#### Scenario: an empty form takes the template
@e2e exclude The empty-form half is covered by the vitest beside the guard above.

- **GIVEN** a close form whose outcome text is empty
- **WHEN** a handler picks a result template
- **THEN** the outcome text SHALL be the template body

### Requirement: The unreachable close form is retired once this one works (REQ-TPL-08)

`CaseTransitionConfirmDialog` SHALL be retired once the requirements above are
implemented and tested, and SHALL NOT be retired before. It is the only
component that mounts `TemplatePicker` today, so deleting it first would
remove the only implementation of a requirement that is still written down.

#### Scenario: the picker has one home, and it is reachable
@e2e exclude Structural, covered by tests/vitest/registryOrphans.spec.js.

- **WHEN** the retirement lands
- **THEN** exactly one component in `src/` SHALL mount `TemplatePicker`
- **AND** a manifest action SHALL name the surface that mounts it
