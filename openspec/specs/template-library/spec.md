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
