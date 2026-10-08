# portal-contribution Specification

## Purpose

dossiq contributes its portal data to portaliq rather than rendering portals
itself. One provider declares three audiences: supplier, citizen and inspector.
Each audience is scoped to what that reader may see, by subject and by field.
The in-app portal views retire; the backend API and the schemas stay.

## Requirements

### Requirement: REQ-PORTAL-001 — The provider MUST declare three portal audiences (supplier, citizen, inspector)

`OCA\Dossiq\Portal\PortalContributionProvider` MUST expose
`getAudiences()` returning exactly `['supplier','citizen','inspector']` and keep
`getAudience()` returning `'supplier'` as the contract-v1 fallback. It MUST remain
a plain class — no Portaliq import, no `implements`, no info.xml dependency, no
constructor dependencies — so it is inert when Portaliq is absent.
`getContribution($subject)` MUST branch on `$subject['audience']` and MUST return
`null` for any audience dossiq does not serve (fail-closed, ADR-005).

#### Scenario: Provider advertises all three audiences

- GIVEN the Portaliq registry probes the dossiq provider
- WHEN it calls `getAudiences()`
- THEN it receives `['supplier','citizen','inspector']` and `getAudience()` returns `'supplier'`

#### Scenario: Unserved audience contributes nothing

- GIVEN a resolved subject whose `audience` is not one dossiq serves
- WHEN `getContribution($subject)` is called
- THEN it returns `null`

### Requirement: REQ-PORTAL-002 — The citizen audience MUST expose the 'Mijn gemeente' surface as subject-scoped, field-projected collections plus one safe create

For `audience: 'citizen'`, `getContribution()` MUST return the collections
`mijnZaken` (`case`, scopeField `portaalSubject`), `berichten`
(`portaalBericht`, `kind: 'inbox'`, scopeField `recipientRef`) and `verzoeken`
(`portaalVerzoek`, scopeField `submitterRef`), each carrying a `fields` whitelist
that omits staff/internal columns, and exactly one action `createKlacht`
(`portaalVerzoek`, scopeField `submitterRef`) whitelisting only citizen-authored
content with no case cross-reference. The bezwaar and message-reply creates MUST
NOT be declared (deferred write-IDOR, portaliq#16).

#### Scenario: Citizen sees their own cases, inbox and requests

- GIVEN a resolved citizen subject
- WHEN `getContribution()` runs for `audience: 'citizen'`
- THEN the manifest lists `mijnZaken`, `berichten` and `verzoeken`, each with a `fields` whitelist, and a single `createKlacht` action

#### Scenario: Every citizen scopeField and projected field exists on its schema

- GIVEN the citizen collections' schemas in `dossiq_register.json`
- WHEN each collection's `scopeField` and each `fields` entry is checked against the schema properties
- THEN every one exists (register-drift pin)

### Requirement: REQ-PORTAL-003 — The inspector audience MUST expose an external field inspector's assigned inspections, scoped by a non-NC-account reference

For `audience: 'inspector'`, `getContribution()` MUST return the read collections
`inspectieRapporten` (`inspectieRapport`) and `checklistRuns`
(`inspectionChecklistRun`), both scoped by `assignedInspectorRef` — the external
inspector's pseudonymous portal reference, distinct from the internal `inspector`
NC-user-UID column — and both field-projected to the inspector's own result-level
data. No create action is declared (deferred run-submit, portaliq#16).

#### Scenario: External inspector sees only their assigned inspections

- GIVEN a resolved inspector subject
- WHEN `getContribution()` runs for `audience: 'inspector'`
- THEN the manifest lists `inspectieRapporten` and `checklistRuns`, both scoped by `assignedInspectorRef`, with no create action

### Requirement: REQ-PORTAL-004 — The in-app portal Vue surfaces MUST be retired while the backend API and schemas remain

The in-app supplier portal (`/leverancier`), citizen portal (`/portaal/*`) and
field-inspection nav page (`/inspecties`) Vue views, their manifest fragments,
their `PortaalGroup` nav group and their routes MUST be removed from the dossiq
frontend. The backend controllers/services and their `/api/leverancier-portaal/*`,
`/api/portaal/*` and `/api/inspections/*` endpoints, and the OpenRegister schemas,
MUST remain unchanged (Portaliq reads OpenRegister directly).

#### Scenario: Retired portal nav entries no longer render

- GIVEN the dossiq app navigation is built from the manifest fragments + menu-layout
- WHEN the sidebar renders
- THEN no `LeverancierDashboard`, `MijnZaken`, `MijnNotificaties` or `Inspecties` menu entry and no `PortaalGroup` group appears

#### Scenario: Backend supplier/portal/inspection endpoints still resolve

- GIVEN the retired in-app views are deleted
- WHEN a request hits `/api/leverancier-portaal/*`, `/api/portaal/*` or `/api/inspections/*`
- THEN the backend controllers still resolve (only the in-app Vue surfaces were removed)

### Requirement: Feed entries default to internal and the portal sees only public ones (REQ-PC-10)

Every note, contact moment and status change dossiq writes SHALL carry the
visibility flag, defaulting to internal; the note and contact forms SHALL
offer "Visible to the applicant". Berichtenbox messages, portal messages
and delivered beschikkingen SHALL be public. The portal contribution,
`#PublicStatus` and an access link the case is shared through SHALL show only
public entries, and no entry any of them serves SHALL name who wrote it.

#### Scenario: A note stays inside
@e2e tests/e2e/timeline-visibility.spec.ts

- **GIVEN** you add a note to a case without ticking Visible to the applicant
- **WHEN** the applicant opens the case through the link it was shared with
- **THEN** the note SHALL NOT be shown

#### Scenario: A delivered letter is on the timeline
@e2e tests/e2e/timeline-visibility.spec.ts

- **GIVEN** a beschikking delivered on the case
- **WHEN** the applicant opens the case through the link it was shared with
- **THEN** the delivery SHALL be listed with its date

#### Scenario: The contribution carries the same list
@e2e exclude cross-app; covered by PortalContributionProviderTest asserting the timeline equals `publicEntries()`

- **GIVEN** a case with two public and three internal entries
- **WHEN** the contribution is built
- **THEN** its timeline SHALL hold the two public entries

### Requirement: A portal write that names a case SHALL declare that case as a guarded reference (REQ-PC-20)

Every create the citizen audience declares that accepts a case reference
SHALL declare that field in `crossRefs`, naming the `case` schema and the
`portalSubject` scope field. It SHALL mark it required, except for
`createKlacht`, whose case is optional: a reference it carries is still
guarded against the citizen's own cases. No such create SHALL accept a case
reference without a guard.

#### Scenario: A citizen objects to their own case

- **GIVEN** the citizen audience's `createBezwaar`
- **WHEN** it is declared
- **THEN** `againstCaseId` SHALL be guarded against the citizen's own cases
- @e2e exclude {the guard is enforced inside Portaliq and has no dossiq browser path; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testEveryCitizenCreateNamingACaseGuardsIt}

#### Scenario: A citizen replies about their own case

- **GIVEN** the citizen audience's `replyToMessage`
- **WHEN** it is declared
- **THEN** `caseId` SHALL be guarded against the citizen's own cases, and the reply SHALL be scoped by who sent it
- @e2e exclude {same enforcement point; asserted in the same test}

#### Scenario: A citizen complains about their own case

- **GIVEN** the citizen audience's `createKlacht`
- **WHEN** it is declared
- **THEN** `againstCaseId` SHALL be guarded against the citizen's own cases and SHALL NOT be required
- @e2e exclude {the guard is enforced inside Portaliq; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testEveryCitizenCreateNamingACaseGuardsIt}

### Requirement: What a portal write IS SHALL come from the server (REQ-PC-21)

The `kind` of a bezwaar and the `direction` of a message SHALL be stamped
from the action's `defaults` and SHALL NOT appear in its whitelisted fields.

#### Scenario: A bezwaar cannot arrive as a klacht

- **GIVEN** the citizen audience's `createBezwaar`
- **WHEN** it is declared
- **THEN** `kind` SHALL be absent from its fields and present in its defaults
- @e2e exclude {a manifest shape; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testTheKindAndDirectionAreStampedNotOffered}

### Requirement: The inspector submit SHALL accept no case or template (REQ-PC-22)

Submitting a checklist run SHALL be an update on a run the inspector is
already assigned, whose whitelisted fields carry neither the case nor the
template, and whose resulting status SHALL come from the action rather than
the request.

#### Scenario: An inspector submits the run they hold

- **GIVEN** the inspector audience
- **WHEN** its actions are declared
- **THEN** `submitChecklistRun` SHALL be an update scoped by `assignedInspectorRef`, accepting no `case` and no `template`
- @e2e exclude {an external inspector session cannot be staged from dossiq's own browser surface; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testInspectorContributionShape}

### Requirement: REQ-PORTAL-005: An inbox collection MUST name the fields that carry its message

Every `kind: inbox` collection dossiq contributes SHALL declare `messageFields`, naming which of its schema's fields carry the inbox's text, date, read date and attachments. Each named field SHALL exist on the schema and, when the collection declares `fields`, SHALL be among them, because portaliq projects before it maps. The citizen `berichten` collection SHALL declare `filesDownload: true`, so the files a handler attaches to a message reach the resident.

#### Scenario: A handler's message shows its text, date and files
- **GIVEN** a `portaalBericht` to a resident with `content`, `sentAt` and an attached file
- **WHEN** the resident opens their portal inbox
- **THEN** the message shows its text, its date, unread, and the file as a download

#### Scenario: Every named field reaches portaliq
- **GIVEN** the inbox collections of every audience
- **WHEN** each `messageFields` entry is checked against the schema and the `fields` whitelist
- **THEN** every named field exists on the schema and survives the projection

### Requirement: REQ-PORTAL-021: A resident MUST open their own case on a page that can withdraw it

The resident contribution SHALL declare `pages`. The page with id `mijnZaken` SHALL be the first page that shows the `mijnZaken` collection and SHALL carry a `citizenCase` block on it, so portaliq's case screen (status, amend, documents, withdraw) renders for the case a resident opens. Every other listable collection SHALL keep a page with its collection id as page id, the first create action of its schema, the collection table and the selected row.

#### Scenario: A resident withdraws their Woo request from Mijn zaken
- **GIVEN** a resident with an open Woo request whose case type declares a portal withdrawal
- **WHEN** the resident opens the request from "Mijn zaken" on the portal site
- **THEN** the case page shows the case screen with a withdraw button
- **AND** after confirming, the case shows as withdrawn

#### Scenario: The other collections keep their pages
- **GIVEN** the resident contribution
- **WHEN** portaliq resolves its pages
- **THEN** `berichten` and `verzoeken` each have a page with their create action, table and detail, under their own collection id

### Requirement: REQ-PORTAL-022: Mijn zaken MUST show the status in words

The `mijnZaken` collection SHALL declare `statusLabelField: statusPublicLabel`, a field it projects, so portaliq's "Mijn zaken" list shows the status's public label instead of the statusType uuid in `status`.

#### Scenario: A resident reads the status of their Woo request
- **GIVEN** a resident's Woo request with status Ontvangen
- **WHEN** the resident opens "Mijn zaken" on the portal site
- **THEN** the row shows "Ontvangen", not a uuid

### Requirement: Every dossiq portal page names its menu group
The contribution MUST declare its pages for every audience it serves, and every page MUST carry a `group`: "Mijn
zaken en verzoeken" for `citizen` and `client`, "Opdrachten en facturen" for `supplier`, "Inspecties" for
`inspector`. No resident page MAY be named "Mijn zaken" or "Berichten", the names of the site's own case list and
inbox. A page MUST still show the `mijnZaken` collection with its detail, so a case opens from the site's case list
and from a notice link.

#### Scenario: The resident menu
- **GIVEN** a resident signed in on the site
- **WHEN** the site builds the menu from dossiq's contribution
- **THEN** dossiq's pages MUST sit under "Mijn zaken en verzoeken" as "Voortgang van uw zaken", "Een bericht beantwoorden" and "Mijn verzoeken"
- **AND** "Mijn zaken" and "Berichten" MUST appear once, as the site's own sections

#### Scenario: A case opens from the site's case list
- **GIVEN** a resident with a dossiq case in the site's "Mijn zaken"
- **WHEN** they open it
- **THEN** the page "Voortgang van uw zaken" MUST open with that case selected

### Requirement: The decision notice says what happened
When the decision on a Woo request case is published for the first time and a resident follows the case
(`portalSubject`), dossiq MUST write one `portalMessage` in portaliq's register, in Dutch only, with subject "Het
besluit op uw Woo-verzoek is gepubliceerd", a body that names the request and holds an absolute link to the
publication page of the site (`/index.php/apps/portaliq/site?route=/publicatie/<id>`), `ruleKey`
`dossiq.wooRequest.published` and `recordLink` `{app: dossiq, collection: mijnZaken, id: <case>}`. The citizen
contribution MUST declare that key in `notifications` and MUST NOT declare a change rule for the publish. A
republish MUST NOT write a second message. A message that cannot be written MUST NOT fail the publish.

#### Scenario: The first publish
- **GIVEN** a Woo request case the resident started on the portal
- **WHEN** the Woo coordinator publishes the decision
- **THEN** the resident's inbox MUST hold "Het besluit op uw Woo-verzoek is gepubliceerd" with a link to the publication on the site
- **AND** the inbox MUST NOT also hold "<case> is bijgewerkt" for that publish

#### Scenario: A republish
- **GIVEN** a case whose decision is already published
- **WHEN** the coordinator publishes it again
- **THEN** no new message MUST be written

### Requirement: The case page offers bezwaar and klacht for the case on screen

The resident's case page SHALL declare "Bezwaar maken" and "Klacht indienen"
as calls to action with `withRecord: true`, and each action SHALL name
`againstCaseId` as its `recordField`, guarded against the citizen's own cases.
The overview SHALL NOT declare them. A klacht's case reference SHALL NOT be
required.

#### Scenario: A resident objects from their case page

- **GIVEN** a resident on the page of one of their cases
- **WHEN** they press "Bezwaar maken"
- **THEN** the bezwaar SHALL carry that case in `againstCaseId`
- @e2e exclude {a declaration; asserted in tests/Unit/Portal/PortalCasePageTest.php::testTheCasePageCarriesTheResidentsOwnCase and PortalContributionProviderTest::testTheReplyActionNamesTheFieldARecordLandsIn}

#### Scenario: A klacht without a case is still accepted

- **GIVEN** the "Klacht indienen" page, which opens no case
- **WHEN** the resident files a klacht
- **THEN** the klacht SHALL be accepted without `againstCaseId`
- @e2e exclude {a declaration; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testEveryCitizenCreateNamingACaseGuardsIt}

### Requirement: The case page orders its blocks as the Zaak board

The case page SHALL declare Gegevens before Stukken and the timeline.

#### Scenario: The facts come before the documents

- **GIVEN** the resident's case page
- **WHEN** it is declared
- **THEN** its blocks SHALL read tasks, steps, detail, documents, timeline, the calls to action, citizenCase
- @e2e exclude {a declaration; asserted in tests/Unit/Portal/PortalCasePageTest.php::testTheCasePageCarriesTheResidentsOwnCase}
