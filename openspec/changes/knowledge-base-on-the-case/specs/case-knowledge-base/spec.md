## ADDED Requirements

### Requirement: The work instruction for a case type is on the case (REQ-CKB-01)

You open a case and read how your municipality handles this kind of case.
`caseType` SHALL carry an optional `knowledgeBasePage` referencing a
Collectives page, and `#CaseDetail` SHALL show that page on every case of
that type, without anyone linking it per case.

The reference SHALL be a page and never its text. dossiq SHALL store no
article body, because a copy goes stale and, worse, is readable by somebody
the collective's team excludes.

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

### Requirement: The tab is absent without the app (REQ-CKB-04)

The Knowledge tab SHALL declare `requiredApp: 'collectives'`. On an instance
without the app the tab SHALL be absent, not empty and not an error.

#### Scenario: No Collectives, no tab
@e2e exclude Registry declaration, covered by vitest.

- **GIVEN** an instance without the Collectives app
- **WHEN** a case page is rendered
- **THEN** the Knowledge tab SHALL NOT be present
