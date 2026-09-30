# Design: woo-publish-decision-from-the-case

Read at dossiq `development` `db27acb6e` on 2026-09-27.

## What is there

- `appinfo/routes.php:820-821` routes `wOOAssessment#publishDecision` and
  `wOOAssessment#withdrawPublication`. Both check the session, then
  `requireCaseMutationAccess()` (per-case, fail closed, as the spec's
  "Publish action authorization" requires), then read `decisionId` from the body
  and answer 400 without it.
- `WooPublicationService::publish(caseId, decisionId)` (`lib/Service/WooPublicationService.php:217`)
  loads the case and the decision, collects the disclosable documents, sends the
  publication and writes `wooPublication` (`publicationId`, `publicationUrl`,
  `status: published`) back onto the decision (lines 241-258). `withdraw()`
  (line 391) sets `status: withdrawn` and `withdrawnAt`.
- `WOODecisionService::assembleDecision()` (`lib/Service/WOODecisionService.php:85`)
  writes the Woo besluit: `case`, `decisionType: 'WOO-besluit'`, `wooSummary`,
  `weigeringsgronden`, `assessmentCount`, `decidedBy`.
- The `decision` schema (`lib/Settings/dossiq_register.json`) declares `case`,
  `decisionType` (a uuid `$ref` to `decisionType`), `title`, `description`,
  dates, `explanation` and a few more. It declares none of `wooPublication`,
  `wooSummary`, `weigeringsgronden`, `assessmentCount` or `decidedBy`, and
  `decisionType` receives a title where a uuid is declared.
- `src/services/wooPublicationApi.js` has no importer. `#CaseDetail` has 22
  header actions and none is Woo. The Decisions tab is decidiq's leaf
  (`case-decisions-pane`, `BesluitvormingLeafTab`), not a place for a dossiq
  control.

## D-1. The server finds the decision

The endpoints keep accepting `decisionId`. When it is absent they resolve the
case's Woo decision: the `decision` objects with `case` equal to the case and a
non-empty `wooSummary`, which `assembleDecision()` always writes. One match is
used. None answers 409 `no_woo_decision`; more than one answers 409
`several_woo_decisions` and names their ids. A header action cannot carry a
decision id it does not have, and the rule "which decision is the Woo one"
belongs next to the code that writes it.

## D-2. The case carries its publication state

A header action gates on the case object (the local `visibleWhen` mode, as
`case-claim` does). `endpoint` mode fetches its url verbatim and cannot name
this case, and `source` mode over `decision` would read the first decision on
the case, which need not be the Woo one (see `case-claim-action` design D-2 for
both limits). So the case gets two properties:

- `wooPublicationStatus`: enum `none`, `ready`, `published`, `withdrawn`,
  default `none`, read only.
- `wooPublicationUrl`: string, uri, read only.

Only three code paths write them, each in the same request as its decision
write: `assembleDecision()` sets `ready`, `publish()` sets `published` and the
url, `withdraw()` sets `withdrawn`. A repair step computes both for existing
Woo cases once. This is a projection with named writers, the shape
`CaseResultWriter` already uses for `archiveNomination`.

## D-3. Declare what the writers send

`decision` gains `wooPublication` (object: `publicationId`, `publicationUrl`,
`status`, `publishedAt`, `withdrawnAt`), `wooSummary` (object),
`weigeringsgronden` (array of strings), `assessmentCount` (integer) and
`decidedBy` (string). Before anything else, task 1.1 proves whether
OpenRegister keeps an undeclared property on this schema today: save a decision
with `wooPublication`, read it back through `ObjectService::find()`. The answer
goes in the PR. If it was being dropped, every publication so far lost its id,
and the repair step in 3.1 cannot recover it from the decision; it then reads
OpenCatalogi's publication by the case reference instead.

`decisionType: 'WOO-besluit'` is left alone here. Pointing it at a seeded
decision type uuid is part of the decidiq move (BLOCKED-2), and the lookup in
D-1 does not depend on it.

## D-4. The surface

- Header action `woo-publish`, label "Publish (Woo)", `api-call` POST to
  `/apps/dossiq/api/cases/@objectId/woo/publish`, visible when
  `wooPublicationStatus` is `ready` or `withdrawn`, success message "The
  decision is published."
- Header action `woo-withdraw`, label "Withdraw publication", `api-call` POST to
  `/woo/withdraw`, visible when `wooPublicationStatus` is `published`, with a
  confirmation.
- The Data tab's core section shows `wooPublicationStatus` and
  `wooPublicationUrl` as a link, only on a case whose status is not `none`.

A refusal shows the server's sentence, as `case-claim` does. `no_publishable_documents`
reads "Nothing can be published yet: no document is assessed as public."

## Risks

- The spec's requirement names a Vue file that no longer exists; the MODIFIED
  requirement replaces the file with the page, so the spec stops pointing at a
  ghost.
- OpenCatalogi absent: `checkAvailability()` already answers
  `{available: false, reason}`; the action shows that reason and changes
  nothing.
- A mirror can drift. The three writers are the only writers, and the repair
  step is idempotent, so a re-run corrects a drift.

## Woo journey additions (2026-09-30)

Contract: hydra `openspec/changes/woo-citizen-journey/design.md` C6 and C3.
Read against opencatalogi `development` `4f4c377a` (publication schema 0.0.5).

### D-5. The publication fields

`buildPayload()` adds `publicationKind: woo-besluit`, `wooCategory` (the
mapper's code, `infocat014`: the existing field opencatalogi's Woo sitemap and
search facet read; the settled contract has no `informatiecategorie` property),
`caseReference` (the case uuid, as today) and `period: {from, to}` from `case.wooRequest`. `publicationDate` is the
moment of publishing in ISO 8601: the schema declares a date-time, and public
read access is `publicationDate <= now`. The payload stops sending
`tooiCategorieUri`, `tooiCategorieNaam` and `documentCount`, which the schema
does not declare and the store strips.

### D-6. Documents are files on the publication

opencatalogi's register has no `document` schema any more; documents are files
attached to the publication (`attachments-are-files`). `attachDocument()` would
throw on every publish. The disclosable documents are attached with
`attachFile()` on the publication id. `withdraw()` writes `depublicationDate`,
the schema's name, not `depublicatiedatum`.

### D-7. Back to the dossier

When `case.wooRequest.collectionId` is set, `publish()` reads the collection as
the system and appends `{id, publication, attachment: null, note, addedAt,
addedBy: "dossiq"}` unless an item for that publication is already there. It
changes no other item. A failure is logged at warning and the publish still
succeeds: the publication is the decision, the dossier item is a courtesy.

### D-8. The notice rides portaliq's change rule

C3 says the sender writes a `portalMessage`. portaliq's listener skips
`portalMessage` creates ("dispatched by whoever wrote them"), so a message
written by dossiq would reach the inbox and never the email.
The path case notices already use is a change rule: the citizen contribution
declares `{ruleKey: "dossiq.wooRequest.published", collection: "mijnZaken",
on: {field: "wooPublicationUrl", operator: "changed"}, titleField: "title"}`, and portaliq writes the
message and dispatches it when the field changes. `wooPublicationUrl` changes
once, on the first publish, so a republish or a withdraw sends nothing.
`wooPublicationUrl` joins the resident's case fields, so the notice links to a
page that shows it. When portaliq is absent nothing listens and nothing fails.
The notice goes to the portal inbox and by email only. Berichtenbox is not
used in this journey (Ruben, 30 September 2026): it needs the resident's BSN,
and nothing here stores one.
