## ADDED Requirements

### Requirement: Cross-surface feature vocabulary SHALL match shipped code
The app's four public surfaces — `appinfo/info.xml`, `src/manifest.json` nav/menu, the
conduction.nl product page (EN + NL), and the `docs/` site — SHALL describe the same canonical
feature vocabulary, derived from `lib/Controller/`, `lib/BackgroundJob/`, and the manifest nav.
Any feature named on the product page or in info.xml SHALL be traceable to a controller, service,
background job, or Vue view in the shipped code.

#### Scenario: VTH process templates named on the product page match shipped templates
- **WHEN** the product page lists named VTH case-type templates
- **THEN** every named template SHALL exist under `lib/Settings/vth-templates/` or
  `lib/Settings/templates/`
- **AND** no template name SHALL be invented or drawn only from free-text sample data

#### Scenario: Third-party/standard integration claims require live code
- **WHEN** the product page or info.xml claims an integration with a named external product,
  service, or standard (e.g. a document/signing engine, a workflow-automation tool, a PII-redaction
  library, a compliance standard)
- **THEN** the claim SHALL be backed by a real service call, adapter implementation, or dependency
  declaration in `lib/` or `composer.json`/`package.json`
- **AND** a merely-mocked adapter (e.g. an interface whose only implementation is a `Mock*Adapter`)
  SHALL be described as pluggable/optional, not as a live default behaviour

#### Scenario: Cross-app integration claims require shared code or shared data wiring
- **WHEN** the product page claims that another Conduction app (e.g. a citizen-facing portal)
  integrates with Dossiq (e.g. "applicants track their case live")
- **THEN** there SHALL be at least one code reference in either app pointing at the other (shared
  register schema consumption, an API call, or an explicit adapter), or the claim SHALL be reworded
  to describe ecosystem positioning rather than a functioning integration

#### Scenario: Dashboard widget names on the product page match real widget titles
- **WHEN** the product page names specific dashboard widgets shipped with the app
- **THEN** each named widget SHALL correspond to a real `OCP\Dashboard\IWidget` implementation's
  `getTitle()` return value registered in `lib/AppInfo/Application.php`

#### Scenario: Version and release-status labels are sourced from info.xml
- **WHEN** the product page displays a version number and release-status badge (e.g. Beta/Stable)
- **THEN** the version SHALL match `appinfo/info.xml`'s `<version>` value
- **AND** the status label SHALL reflect the app's actual major-version maturity (pre-1.0 = Beta)
