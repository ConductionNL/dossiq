# admin-settings Delta: r6-dossiq-titles-related-cases-requests

## ADDED Requirements

### Requirement: The case type list heads itself under its section

The case type list on the admin settings page SHALL carry its heading as an
`<h3>` under the `<h2>` of the "Case type management" section, and SHALL NOT
render an `<h1>`. Its Add button SHALL read "Add case type".

#### Scenario: Reading the heading outline
<!-- @e2e exclude Heading level; proven by tests/vitest/caseTypeListHeading.spec.js (fails on the old h1) and live on :8099. -->
- **GIVEN** an admin opens the dossiq admin settings
- **WHEN** a screen reader lists the headings
- **THEN** "Case types" is a level 3 heading under "Case type management", and the page has no second level 1 heading

### Requirement: Sibling apps are named by product, looked up by id

Each row under "Apps dossiq needs" and "Apps dossiq works with" SHALL show the
app's product name (OpenRegister, Integriq, Filinq, Humaniq, Decidiq,
Portaliq, Pipelinq, Hermiq, Thematiq). The id the row is looked up by SHALL
stay the declared key, until that app's own `<id>` moves.

#### Scenario: Integriq installed under its old id
<!-- @e2e exclude Server-side declaration; proven by PrerequisitesTest::testEveryAppRowShowsAProductNameAndKeepsItsLookupId. -->
- **GIVEN** the instance has `openconnector` installed
- **WHEN** the admin opens the prerequisites block
- **THEN** the row reads "Integriq", is marked present, and was looked up as `openconnector`

### Requirement: The page-view preference key is accepted

The preferences endpoint SHALL read and write a key of the form
`cn_page_view:<pageId>`, where the page id uses letters, digits, `.`, `_` and
`-`, and the whole key is at most 59 characters. A colon anywhere else SHALL
still be refused with 400.

#### Scenario: Opening the landing page
<!-- @e2e exclude Controller validation; proven by PreferencesControllerTest::testThePageViewKeyIsAccepted (fails on the old charset) and live on :8099. -->
- **GIVEN** a signed-in user
- **WHEN** the landing page reads `GET /apps/dossiq/api/preferences/cn_page_view:MyWorkHome`
- **THEN** the answer is 200

#### Scenario: Any other colon
<!-- @e2e exclude Controller validation; proven by PreferencesControllerTest::testAColonOutsideThePageViewPrefixIsRefused. -->
- **WHEN** a caller reads `other:key` or `cn_page_view:a:b`
- **THEN** the answer is 400 and nothing is read
