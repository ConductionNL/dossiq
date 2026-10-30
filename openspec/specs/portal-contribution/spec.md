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

### Requirement: The case collection publishes the documents a resident may see (REQ-PORTAL-018)
`mijnZaken` MUST declare `documents: {label: "Stukken", provider: "caseDocuments"}`.
`caseDocuments(caseId)` MUST return, for that case only, each document whose
status is final or archived, whose confidentiality is `openbaar`, `beperkt_openbaar` or
`zaakvertrouwelijk`, and that a decision on the case links or that the
organisation sent, as `{id, title, kind, date, file}` with the file on the
case object. A document a decision links MUST carry `kind: decision` and the
decision date.

#### Scenario: The resident sees the decision and the letters sent to them
- **GIVEN** a case with a final decision letter, a final outgoing letter, a draft, an internal note and a neighbour's incoming letter
- **WHEN** the resident opens the case in the portal
- **THEN** the decision letter MUST be listed first under Decision and the outgoing letter under Documents, and nothing else from the organisation

#### Scenario: Nothing is answered without a case
- **GIVEN** OpenRegister is not available, or the case id is empty
- **WHEN** portaliq asks for the case's documents
- **THEN** the answer MUST be an empty list

### Requirement: The inbox names the message box recipient (REQ-PORTAL-019)
The `berichten` inbox MUST declare `messageBox: {recipientProvider: "messageBoxRecipient"}`.
`messageBoxRecipient(messageId)` MUST return the applicant's BSN only for an
organisation's message on a case whose applicant is a person with a valid BSN
and to whom the message is addressed, and null for every other message.

#### Scenario: A letter to the applicant reaches their message box
- **GIVEN** a handler's message on a case filed by a resident with a valid BSN, addressed to that resident
- **WHEN** portaliq asks who receives it in the message box
- **THEN** the answer MUST be that resident's BSN

#### Scenario: A reply or a message to a representative names nobody
- **GIVEN** a resident's own reply, or a message addressed to someone other than the applicant
- **WHEN** portaliq asks who receives it
- **THEN** the answer MUST be null and nothing MUST be sent

### Requirement: The overview greets and names its lists (REQ-ROD-001)
The citizen contribution's `overzicht` page MUST open with a portaliq `greeting` block without
the date, so the page greets the resident by the time of day and their first name. Its `tasks`
block MUST carry the label "Wat u nog moet doen", its `cases` block "Lopende zaken" and its
`inbox` block "Nieuwe berichten". Nothing else on the page MUST change.

#### Scenario: Sanne opens her overview in the afternoon
@e2e exclude PHPUnit tests/Unit/Portal/PortalContributionProviderTest.php reads the declaration; the words are portaliq's (GreetingBlock) and the coordinator sees them live
- **GIVEN** the Zuiddrecht example resident, signed in at 14:00
- **WHEN** the overview opens
- **THEN** it reads "Goedemiddag, Sanne", then "Wat u nog moet doen", "Lopende zaken" and "Nieuwe berichten" above their lists

#### Scenario: Only the declaration changes
@e2e exclude PHPUnit tests/Unit/Portal/PortalCasePageTest.php pins the case page's blocks
- **GIVEN** the citizen contribution
- **WHEN** the pages are built
- **THEN** the case page's blocks are as before, in the same order

### Requirement: A resident's writes on their own case are declared (REQ-PORTAL-013)
The contribution MUST declare exactly one `type: update` action on `case`
carrying `citizenWrite` with `typeField: caseType`, `typeRegister: dossiq` and
`typeSchema: caseType`, scoped by `portalSubject`, whose `fields` whitelist
holds only fields the applicant supplied, and MUST serve it to every audience
the case collection is served to.

#### Scenario: A resident opens their case screen
- **GIVEN** a resident signed in with DigiD with a dossiq case of type "Melding openbare ruimte"
- **WHEN** they open the case in the portal
- **THEN** the case screen MUST show the case instead of "This case cannot be changed from the portal."

#### Scenario: A resident corrects their description
- **GIVEN** the same case in status Ontvangen, where the case type opens `description` to `client`
- **WHEN** the resident corrects the description and saves
- **THEN** the case MUST carry the new description, and the handler MUST see the amendment on the case timeline

### Requirement: The case type decides what a resident may change and withdraw (REQ-PORTAL-014)
A case type MUST be able to declare `portalWritable`, `portalAmendmentWindow`,
`portalDocumentWindow` and `portalWithdrawal` in the shapes portaliq reads. A
case type MUST NOT be saved with a `portalWithdrawal` whose `targetStatus` is
not reachable from each of its open statuses.

#### Scenario: A resident withdraws a report still waiting to be picked up
- **GIVEN** a case type declaring withdrawal while Ontvangen, onto Ingetrokken, and a resident's case in Ontvangen
- **WHEN** the resident withdraws it in the portal and confirms
- **THEN** the case MUST be in status Ingetrokken and the assignee MUST be told

#### Scenario: An unreachable withdrawal status is refused
- **GIVEN** a functional administrator editing a case type whose workflow has no transition from Ontvangen to Ingetrokken
- **WHEN** they save a withdrawal onto Ingetrokken while Ontvangen
- **THEN** the save MUST be refused with a sentence naming Ingetrokken

### Requirement: The Woo request action declares its four steps (REQ-SWS-001)
The actions `startWooVerzoek` and `startWooVerzoekAlgemeen` MUST declare `steps`: "Uw vraag"
(`onderwerp`, `omschrijving`), "Periode en documenten" (`periodeVan`, `periodeTot`,
`documentSoorten`, `toelichting`), "Uw gegevens" (`verzoekerNaam`, `verzoekerEmail`,
`verzoekerType`) and "Controleren en versturen" as a review of every answer. Every whitelisted
field MUST belong to exactly one step. Each step MUST use the keys `id`, `title`,
`description` and `fields`; the review step MUST carry `review: true` and no fields. The
labels MUST be the ones of `DossiqWoo.dc.html`. No field MUST be declared `required`: portaliq
drops `required` on an action without a schema (portaliq REQ-SMF-023). The steps, draft and
confirmation follow portaliq REQ-SMF-020 to REQ-SMF-022, which cover endpoint actions with
`fields`. This builds on REQ-PORTAL-020 and keeps its endpoint, method
and assertion.

#### Scenario: A resident moves through the steps
- **GIVEN** a resident signed in with DigiD on a site that renders declared steps
- **WHEN** they start "Informatie opvragen (Woo-verzoek)"
- **THEN** the form MUST show step 1 of 4, "Uw vraag", and only its two fields
- **AND** step 4 MUST list every answer with a link to change the step it came from

#### Scenario: No form-only required field
- **GIVEN** the declared action
- **WHEN** the provider test reads its `fieldConfigs`
- **THEN** no field MUST carry `required`

#### Scenario: Every field has a step
- **GIVEN** the declared action
- **WHEN** the provider test compares `fields` with the fields of all steps
- **THEN** each field MUST appear in exactly one step

### Requirement: A resident starts a Woo request without a dossier (REQ-SWS-002)
The citizen contribution MUST offer `startWooVerzoekAlgemeen`, posting to the same route as
`startWooVerzoek`, with the same steps and fields except `collectionId`, and without
`attachTo`. `startWooVerzoek` MUST keep `attachTo` and `rowField` unchanged.

#### Scenario: From the home page
- **GIVEN** a resident with no dossier
- **WHEN** they send a Woo request through `startWooVerzoekAlgemeen`
- **THEN** dossiq MUST answer 201 and the case MUST show under the resident's cases without case objects

### Requirement: A half-filled Woo request can be saved and resumed (REQ-SWS-003)
Both Woo actions MUST declare `draft` with `retentionDays: 30`. Dossiq MUST NOT receive or store
anything before the resident sends the request.

#### Scenario: Save and come back
- **GIVEN** a resident on step 2 of the Woo request on a site that stores drafts
- **WHEN** they choose "Opslaan en later verdergaan" and return the next day
- **THEN** the form MUST reopen on step 2 with their answers
- **AND** no case MUST exist for the unsent request

### Requirement: The confirmation names the case and the date (REQ-SWS-004)
Both Woo actions MUST declare a `confirmation` with a title, a body using `{identifier}` and
`{deadline}`, and a sentence on where to find the request. A body sentence whose placeholder has
no value MUST be left out.

#### Scenario: The resident reads their case number
- **GIVEN** a request sent and answered with identifier 2026-0003 and deadline 30 October 2026
- **WHEN** the confirmation shows
- **THEN** it MUST read "Uw zaaknummer is 2026-0003. U krijgt uiterlijk 30 oktober 2026 antwoord."

### Requirement: A resident starts a Woo request from the portal (REQ-PORTAL-020)
The citizen contribution, served to `citizen` and `client`, MUST offer the
endpoint action `startWooVerzoek` (`POST
/index.php/apps/dossiq/api/portal/woo-verzoek`, fields `collectionId`,
`onderwerp`, `omschrijving`, `periodeVan`, `periodeTot`), attached to the
dossier page by `attachTo: {app: "opencatalogi", schema: "collection"}` and
`rowField: "collectionId"`. The receiving
route MUST verify portaliq's `X-Portal-Subject` assertion before anything else,
take the resident from its `sub` claim and never from the body, and call
`WooRequestIntake::start()` with `origin: portal`. It MUST answer 201 with
`{caseId, caseUrl}`, 401 for a missing or invalid assertion, 403 for an
audience other than `citizen` or `client`, 400 for an
unusable request and 404 for a dossier that is not the resident's. Implements
hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`, "A Woo request MUST be created by one dossiq path, from the portal and
from pipelinq alike".

#### Scenario: A signed-in resident submits the form
- **GIVEN** a resident signed in with DigiD (audience `client`) on their dossier page
- **WHEN** they submit "Start een Woo-verzoek" with an onderwerp
- **THEN** portaliq forwards the action and dossiq answers 201 with the new case
- **AND** the case MUST show under Mijn zaken with its deadline

#### Scenario: A forged request
- **GIVEN** a request to the route without a valid assertion, or with a subjectRef in the body
- **WHEN** it arrives
- **THEN** dossiq MUST answer 401 for the missing assertion and MUST ignore any subjectRef in the body

### Requirement: A resident reads dossiq's messages in full in the portal inbox (REQ-PORTAL-005)
The citizen `berichten` inbox collection MUST project `body`, `receivedAt` and
`read` from `portaalBericht`, and MUST declare `filesDownload`, so the shared
portal inbox shows a dossiq message's text, date, read state and attachments.
Marking a message read MUST record when the resident read it.

#### Scenario: A message from the handler shows in full
- **GIVEN** a handler sent a message with one attachment about the resident's
  case
- **WHEN** the resident opens the inbox in the portal
- **THEN** they MUST see the subject, the text, the date and the attachment
- **AND** opening the attachment MUST download it

@e2e exclude Rendered by portaliq's inbox, a sibling repo; dossiq's declarations are pinned by tests/Unit/Portal/PortalConversationTest.php and PortalContributionProviderTest.php.

### Requirement: A reply carries its case without the resident typing it (REQ-PORTAL-006)
The `berichten` collection MUST declare a reply through `replyToMessage` that
carries `caseId` from the message answered. `replyToMessage` MUST offer the
resident's own cases as a choice for `caseId` and MUST accept files as
attachments.

#### Scenario: Reply to a message
- **GIVEN** a message from the handler about case "Kapvergunning Dorpsstraat 4"
- **WHEN** the resident presses Reply, writes a text, adds a photo and sends
- **THEN** the reply MUST be stored on that case with the photo attached
- **AND** the resident MUST NOT have been asked for a case number

@e2e exclude Rendered by portaliq's reply form, a sibling repo; the reply, carry and case choice are pinned by tests/Unit/Portal/PortalConversationTest.php.

### Requirement: A resident asks a question from the case and finds the answer there (REQ-PORTAL-007)
The `mijnZaken` detail MUST offer "Ask a question about this case", which
creates a message on that case, and MUST list the messages about that case in
both directions, newest first, through a `caseMessages` provider that answers
only the resident's own messages.

#### Scenario: Ask on the case page
- **GIVEN** a resident viewing their case in the portal
- **WHEN** they ask "When will I hear back?"
- **THEN** the message MUST be stored on that case
- **AND** the handler's answer MUST appear under Berichten on the same case page

@e2e exclude Rendered by portaliq's case page, a sibling repo; the question and the caseMessages provider are pinned by tests/Unit/Portal/PortalConversationTest.php and PortalCaseMessagesTest.php.

### Requirement: The handler sees a resident's message on the case and answers from it (REQ-PORTAL-008)
A message a resident sends about a case MUST appear on that case's timeline as
an internal portal message entry with a follow-up. The case page MUST let the
handler send a message to the resident of a case with a portal subject, and
Reply on a resident's message. The handler's message MUST reach the resident's
portal inbox and close the follow-up.

#### Scenario: Answer a resident's question from the case
- **GIVEN** a resident asked a question about their case in the portal
- **WHEN** the handler opens the case
- **THEN** the timeline MUST show the question with an open follow-up
- **AND** after the handler presses Reply and sends an answer, the follow-up
  MUST be closed and the answer MUST be in the resident's inbox

### Requirement: The resident sees what the organisation still needs from them (REQ-SRPD-001)
An `aanvullingsverzoek` on a case that has a `portalSubject` MUST carry the same
`portalSubject`, written when the request is asked. The citizen contribution, served to
`citizen` and `client`, MUST offer the collection `vragenAanU` on schema `aanvullingsverzoek`,
scoped by `portalSubject` and projected to `case`, `summary`, `missingItems`,
`hersteltermijn`, `state` and `requestedAt`. No other field of the request MAY be projected.
This is a new requirement: today no request reaches the portal.

#### Scenario: A handler asks, the resident sees the question
- **GIVEN** a resident's Woo request case with a `portalSubject`
- **WHEN** the handler asks for a clarification with summary "Bedoelt u alleen de kap van maart 2026?" and a hersteltermijn of 12 October
- **THEN** the resident's `vragenAanU` MUST list one open request for that case with that summary and that date
- **AND** the request MUST NOT expose `rationale`, `requestedBy` or `pauseReason`

#### Scenario: Someone else's request stays invisible
- **GIVEN** an open request on another resident's case
- **WHEN** the resident reads `vragenAanU`
- **THEN** the request MUST NOT be returned

### Requirement: A case says who must act (REQ-SRPD-002)
The case schema MUST carry `portalTurn`, a calculation that is `applicant` when
`waitingOnApplicant` is true or `waitingOn` is `applicant`, `thirdParty` when `waitingOn` is
`thirdParty`, `us` otherwise, and empty when the case has an `endDate`. The citizen case
projection MUST include `portalTurn`, `waitingOnApplicant` and `waitingOn`. The `mijnZaken`
collection MUST declare `turnField: 'portalTurn'`, `dueField: 'deadline'` and value labels on
`portalTurn`, so a case card reads "U bent aan zet", "Wij wachten op informatie van een ander"
or "De gemeente is aan zet". A closed case MUST show no such line. This is a new requirement.

#### Scenario: The case waits on the resident
- **GIVEN** a running case with an open aanvullingsverzoek
- **WHEN** the resident's overview lists the case
- **THEN** the case card MUST read "U bent aan zet"

#### Scenario: The card reads one declared field
- **GIVEN** the `mijnZaken` collection
- **WHEN** portaliq normalises the manifest
- **THEN** `turnField` MUST be `portalTurn` and `portalTurn` MUST be a projected field

#### Scenario: The case waits on the organisation
- **GIVEN** a running case in a status whose `waitingOn` is `us` and no open request
- **WHEN** the overview lists it
- **THEN** the card MUST read "De gemeente is aan zet"

### Requirement: A case hands the portal its steps (REQ-SRPD-003)
The provider MUST answer `caseSteps(caseId)` with the case type's statuses in `order`, folded
so that consecutive statuses sharing a public label form one step. Each step MUST carry the
public label (or the status name when none is declared), the public description, a state
`done`, `current` or `todo`, and for a done or current step the date the case entered it. The
`mijnZaken` collection MUST declare it as `steps` with the label "Waar staat uw aanvraag?".
The steps MUST come from the case type and never from the page. This is a new requirement.

#### Scenario: A Woo request in assessment
- **GIVEN** a case of the seeded Woo type in status "Zoeken documenten"
- **WHEN** portaliq asks `caseSteps` for it
- **THEN** the answer MUST hold five steps: Ontvangen (done), In behandeling (current), Besluit, Besluit genomen and Afgehandeld (todo)

#### Scenario: Another resident's case
- **GIVEN** a case id that is not in the resident's `mijnZaken`
- **WHEN** portaliq asks for its steps on the resident's behalf
- **THEN** portaliq MUST refuse before calling the provider, as it does for `caseTimeline`

### Requirement: A case names its handling team in public words (REQ-SRPD-004)
The case schema MUST carry `assignedGroupPublicName`, a calculation over the handling team's
public name, empty when the team declares none. It MUST be on the citizen detail fields. An
internal team name MUST never reach the portal. This is a new requirement.

#### Scenario: A team without a public name
- **GIVEN** a case handled by a team that declares no public name
- **WHEN** the resident opens the case
- **THEN** "Behandeld door" MUST NOT be shown

### Requirement: The resident pages are declared, and none of them is a menu entry (REQ-SRPD-005)
The citizen contribution MUST declare the pages `overzicht`, `mijnZaken`, `berichten` and
`verzoeken` with the blocks of design D4, using only block types and keys portaliq
`site-mijn-omgeving-components` defines (`tasks`, `inbox`, `cases`, `steps`, `documents`,
`timeline`, `collection`, `detail`, `action`, `cta`, `citizenCase`). `overzicht` MUST declare
`home: true`. `mijnZaken` MUST be a record page on the case collection, so opening a case
lands on it with that case chosen. Its open-question block MUST be a `tasks` block on
`vragenAanU` with `recordField: case`. "Bericht sturen" MUST be a `cta` on `replyToMessage` with
`withRecord: true`, and `replyToMessage` MUST declare `recordField: 'caseId'`. Every resident page MUST
declare `menu: false`, so the site menu shows one "Zaken" and one "Berichten", both portaliq's
own. The case page MUST hold, in this order, the open question for the case, the steps, the
documents, "Wat er is gebeurd", and beside them the facts, "Bericht sturen" and the
withdrawal.

#### Scenario: One Zaken, one Berichten
- **GIVEN** a resident signed in on a site where portaliq honours `menu: false`
- **WHEN** the menu is built
- **THEN** "Zaken" and "Berichten" MUST each appear once
- **AND** no dossiq page MUST appear as a menu entry

#### Scenario: No placeholder block names
- **GIVEN** the declared resident pages
- **WHEN** portaliq normalises them
- **THEN** no block MUST be dropped as an unknown type

#### Scenario: Only this case's question
- **GIVEN** a resident with open questions on cases 2026-0003 and 2026-0004
- **WHEN** case 2026-0003 is open on the case page
- **THEN** the tasks block MUST list only the question on 2026-0003

#### Scenario: A case opens on its page
- **GIVEN** the resident's case 2026-0003 in portaliq's case list
- **WHEN** they open it
- **THEN** the page `mijnZaken` MUST open with the open question first, the steps, "Documenten", "Wat er is gebeurd", "In het kort" and "Verzoek intrekken"

### Requirement: Dossiq offers its start points to the signed-out home (REQ-SRPD-006)
Each action a resident may start without a dossier, `startWooVerzoekAlgemeen`,
`createBezwaar` and `createKlacht`, MUST carry a one-sentence `summary` of at most 200
characters and the `audiences` it serves, a subset of dossiq's audiences, so a site page can list them as
"Wat wilt u regelen?" tiles. A visitor who is not signed in and picks one MUST be asked to
sign in before the form opens.

#### Scenario: The home lists what a resident can arrange
- **GIVEN** a site page with the start points widget and dossiq installed
- **WHEN** a signed-out visitor opens it
- **THEN** it MUST list "Informatie opvragen (Woo-verzoek)", "Bezwaar maken" and "Klacht indienen" with their summaries

### Requirement: REQ-PORTAL-030: The citizen contribution MUST offer the resident's permits as products tagged with their theme

Dossiq MUST record a `permit` for the resident when a case whose case type declares `issuesPermit` gets a granted decision, with the decision's validity and the details the case type names, and MUST mark it revoked when a later decision revokes it. The citizen contribution MUST declare a collection `mijnVergunningen` on schema `permit`, scoped by `portalSubject`, with `kind: products`, the permit's `theme`, and the title, validity, meta and count fields portaliq's products contract reads. It MUST NOT project a revoked permit. It MUST declare an `update` action that asks for a new licence plate by opening a change case, and MUST NOT let the portal write the permit itself.

#### Scenario: a granted resident permit shows on the theme page
- **WHEN** a "Parkeervergunning bewoners" case of resident S gets a granted decision effective 1 January 2026 until 31 December 2026
- **THEN** a permit for S exists with theme `parkeren`, title "Bewonersvergunning binnenstad", the licence plate from the case and those dates
- **AND** dossiq's citizen contribution for S lists it in `mijnVergunningen` with `kind: products`
- @e2e exclude cross-app render belongs to portaliq's ThemaOverzicht e2e; the contribution and the issuing are covered by PHPUnit

#### Scenario: a revoked permit leaves the list
- **WHEN** a later decision on that case revokes the permit
- **THEN** the permit's status is `revoked` and it is no longer in the collection
- @e2e exclude backend listener; covered by PHPUnit

#### Scenario: the resident asks for a new licence plate
- **WHEN** resident S submits "Kenteken wijzigen" with plate GZ-123-X on her permit
- **THEN** a change case is opened with the permit and the new plate, and the permit's plate is unchanged until that case is decided
- @e2e exclude cross-app write path; covered by PHPUnit and portaliq's action e2e

#### Scenario: another resident's permit
- **WHEN** resident T reads `mijnVergunningen`
- **THEN** S's permit is not returned
- @e2e exclude scope check; covered by PHPUnit

### Requirement: The Woo request form asks dates as dates and the requester as a choice (REQ-WDI-004)

Both Woo request actions (`startWooVerzoek`, `startWooVerzoekAlgemeen`) SHALL declare `periodeVan` and `periodeTot` with `input: date`, and `verzoekerType` as choices whose Dutch words (Inwoner, Journalist, Organisatie) stand over the values the route keeps.

#### Scenario: The period step
- GIVEN a resident opens the Woo request from the overview tile or from a dossier
- WHEN she reaches the period step
- THEN the two dates are asked as dates
- @e2e exclude pinned by `PortalContributionProviderTest::testTheWooRequestAsksDatesAsDatesAndTheRequesterAsAChoice`; the rendering is portaliq's

### Requirement: The Woo request starts from the signed-in resident's name and e-mail address

Both Woo request actions MUST declare `prefill: name` on `verzoekerNaam` and `prefill: email` on `verzoekerEmail`, so portaliq starts those fields from the signed-in resident's own details. The resident MUST still be able to change them.

#### Scenario: Step 3 knows who is asking
- GIVEN Henk Bakker is signed in to the portal
- WHEN he reaches "Uw gegevens" in the Woo request
- THEN his name and e-mail address are already filled in
- @e2e exclude pinned by `PortalContributionProviderTest::testTheWooRequestPrefillsTheRequesterFromTheProfile`; the filling itself is portaliq's and pinned there
