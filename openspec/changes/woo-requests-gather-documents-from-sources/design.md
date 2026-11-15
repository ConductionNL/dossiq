# Design: woo-requests-gather-documents-from-sources

Read at dossiq `development` `db27acb6e` and integriq `development` on
2026-09-27.

## What is there

- The Woo request case type (`lib/Settings/templates/woo-verzoek.json`) has
  eight statuses; the third is "Zoeken documenten".
- A file placed in a case folder becomes a document on the case: the node
  listener and `DocumentProjectionService` from the archived change
  `2026-09-13-documents-live-on-the-case`.
- `WOODocumentAssessmentService::getOutstanding()` lists the case's documents
  without an assessment, so a new document is outstanding without any extra
  step.
- dossiq reaches integriq through `FleetAppId::getService()` and typed commands
  (the pattern of `BerichtenboxService` and `PdokLocatieserverService`), and
  degrades when integriq is absent.
- integriq's `SharePointOnlineAdapter` lists and fetches documents in a site;
  it has no search. Its `Microsoft365Adapter` reads calendar and mail metadata.

## D-1. Ask each source, do not index

The search runs when the handler searches. Nextcloud files are searched through
the platform's unified search provider `files` (the OCS search API the Files app
uses), so it answers with the searcher's own access and with full text when the
instance has full text search. Other cases' documents come from OpenRegister's
unified search provider. integriq sources come through one dossiq endpoint that
dispatches integriq's search command. Nothing is copied until the handler picks
it. An index of every source would be a second store of documents the handler
may not see.

## D-2. One endpoint for integriq, none for the platform

- `GET /api/cases/{id}/woo/sources` answers which sources are available: the two
  platform ones always, integriq's when integriq is installed and a Graph
  connection is configured.
- `POST /api/cases/{id}/woo/sources/search` with `{source, terms, from, to}`
  dispatches integriq's search and answers its rows. It refuses when the caller
  may not change the case (`requireCaseMutationAccess()`, as the other Woo
  endpoints do).
- The dialog calls the unified search API for the two platform sources directly,
  per ADR-022.

## D-3. Adding is copying into the case folder

`POST /api/cases/{id}/woo/sources/add` with a list of picks. A Nextcloud file is
copied into the case folder through `IRootFolder` as the caller, so a file the
caller cannot read cannot be added. An integriq result is fetched through
integriq and written into the case folder. Another case's document is linked,
not copied: a `zaakinformatieobject` join, which the case page already shows as
a linked row. Each add answers per pick, added or refused with the reason; one
refusal does not undo the others.

## D-4. Provenance on the document

The projection gains a `provenance` object: `source` (files, cases or the
integriq connection id), `location` (path, site and folder, or mailbox), `terms`,
`searchedAt` and `searchedBy`. A Woo decision has to say where was searched, and
this is where that answer comes from. The field is written once, at the add.

## Risks

- Graph search needs delegated or application permissions a municipality may
  not grant. The source list says so rather than showing an empty result.
- Large result sets: each source is capped at 50 rows per search with a count
  of the rest, so the handler narrows the terms instead of paging through
  thousands.
- Mail is personal data of third parties. An added mail is a document on the
  case like any other and goes through assessment and redaction before anything
  is published.
