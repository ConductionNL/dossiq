## ADDED Requirements

### Requirement: The work instruction for a case type is on the case (REQ-CKB-01)

You open a case and read how your municipality handles this kind of case.
`caseType` SHALL carry an optional `knowledgeBasePage` referencing a
Collectives page, and `#CaseDetail` SHALL show that page on every case of
that type, without anyone linking it per case.

The reference SHALL be a page and never its text. dossiq SHALL store no
article body, because a copy goes stale and, worse, is readable by somebody
the collective's team excludes.

🔴 A DECLARED WIDGET CANNOT MEET THIS, and that is why the page carries a
component. The value lives on the case TYPE while the page is bound to the
CASE, and `CnDetailPage` reads no `extend` (measured 2026-09-20: the string
does not occur in CnDetailPage.vue of the installed 3.4.0), so the case's
`caseType` is a bare uuid on the page and a widget declared over a dotted
`caseType.knowledgeBasePage` path renders blank while looking configured. The
reference is followed once, in `CaseWorkInstructionPanel`.

The panel SHALL draw a link and never the article. A value that is not an
`http` or `https` URL SHALL be read as a Collectives page path rather than
opened as it stands, because the property carries no `format` on purpose and
a `javascript:` value would otherwise become an `href` on a page an
administrator does not own.

A case type that names no page SHALL draw nothing. A lookup that FAILED SHALL
say so and offer a retry, because a blank panel would otherwise read as a
case type nobody wrote an instruction for.

#### Scenario: Every case of a type shows its instruction
@e2e tests/e2e/knowledge-base-on-the-case.spec.ts

- **GIVEN** a case type whose `knowledgeBasePage` names a collective page
- **WHEN** you open any case of that type
- **THEN** the Knowledge tab SHALL show that page

#### Scenario: A case type without an instruction shows the case's own links
@e2e tests/e2e/knowledge-base-on-the-case.spec.ts

- **GIVEN** a case type with no `knowledgeBasePage` and a case linking one page
- **WHEN** you open that case
- **THEN** the Knowledge tab SHALL show the linked page only

### Requirement: A case links its own pages through the leaf (REQ-CKB-02)

`case.linkedTypes` SHALL include `collectives` and `xwiki`, so a handler can
link the page that explains this particular case. The pages SHALL render
through the existing OpenRegister integration leaf and dossiq SHALL store no
article text of its own.

#### Scenario: dossiq stores no article
@e2e exclude Register declaration, covered by vitest.

- **WHEN** the register fragments are checked
- **THEN** no dossiq schema SHALL carry a property holding article body text

### Requirement: Visibility is the team's, not dossiq's (REQ-CKB-03)

A collective is scoped to a Nextcloud team. A role SHALL see an article when
the group named by its `roleType.ncGroupId` is in that team. dossiq SHALL
neither filter the pages nor copy the article text, so a person who may not
read a page in Collectives cannot read it through a case either.

#### Scenario: A handler outside the team does not read the page
@e2e tests/e2e/knowledge-base-on-the-case.spec.ts

- **GIVEN** a collective scoped to a team that a handler's role group is not in
- **WHEN** that handler opens a case linking a page of that collective
- **THEN** the page content SHALL NOT be shown

### Requirement: Without the app there is no page list, and no empty one either (REQ-CKB-04)

The linked-pages section of the Knowledge tab SHALL be OpenRegister's
`collectives` leaf, which carries its own `requiredApp: 'collectives'`. On an
instance without the app the leaf is never registered, the section draws
nothing, and `CaseSectionsWidget` drops its heading with it. So a reader on
such an instance is shown no empty knowledge base and no error.

🔴 THE TAB ITSELF STAYS, and the earlier wording of this requirement said
otherwise. Measured 2026-09-20 against the installed
`@conduction/nextcloud-vue` 3.4.0: `CnTabsWidget.resolvedTabs` maps every
entry of `content.tabs` and renders a `CnTab` for each one that names a widget
definition, `CnDetailWidgetHost` renders NOTHING for an integration id the
registry does not answer rather than removing itself, and a widget's own
`requiredApp` renders a set-up state rather than an absence. No code path in
the library removes a tab because its child could not resolve. A requirement
saying the tab disappears could therefore only ever have been met by accident,
and an e2e asserting it would have reddened the first time it ran on an
instance without Collectives.

The tab is not empty on such an instance either, because its first section is
the case type's work instruction, which is a dossiq surface over a stored
string and needs no Collectives to draw a link.

#### Scenario: No Collectives, no page list
@e2e tests/e2e/knowledge-base-on-the-case.spec.ts

- **GIVEN** an instance without the Collectives app
- **WHEN** a handler opens the Knowledge tab of a case
- **THEN** the linked-pages section SHALL NOT be drawn
- **AND** no empty page list SHALL be drawn in its place
