---
kind: code
depends_on: []
---

# Proposal: woo-requests-gather-documents-from-sources

OpenSpec pass of 2026-09-27. One row in the opencatalogi matrix
(`ConductionNL/opencatalogi`, `openspec/parity/capabilities.json`) whose
`built.owner` is `ConductionNL/dossiq`.

| matrix | row | capability | own rating | built.state |
| --- | --- | --- | --- | --- |
| opencatalogi | `wr-search-sources` | Search the organisation's other systems (case system, SharePoint, Teams, mail) in one go to gather the documents for a Woo request. | no | none |

## Why

A Woo request asks for every document on a subject, wherever it sits. The Woo
request case type has a stage for exactly this, "Zoeken documenten: zoeken en
verzamelen van relevante documenten" (`lib/Settings/templates/woo-verzoek.json`),
and nothing helps the handler do it. They search each system by hand and
upload what they find.

The matrix evidence, read at opencatalogi `a00f9f8`: "a Woo disclosure batch is
created from a documents list the caller already supplies
(lib/Service/WooService.php:335-355, POST /api/woo/batches ...). Nothing
searches other systems: grep sharepoint, teams, imap and graph in lib finds
nothing relevant. ... the Microsoft 365 adapter reads calendar and mail
metadata only, with no search or document gathering
(lib/Service/Adapter/Saas/Microsoft365Adapter.php:23-35)."

Demand: a tender row. Origin
https://www.tenderned.nl/aankondigingen/overzicht/391449, De Connectie
marktconsultatie (2025-09-04), requirements REQ1 and REQ11; also seen in SWO De
Wolden Hoogeveen "Zoek en Vind applicatie (Woo)" (TenderNed 388421, awarded
2025-08 to eData). No competitor in the matrix rates it yes.

## What changes

- A header action Gather documents on a Woo case opens a search over the
  sources the instance has: Nextcloud files (the platform's unified search),
  documents on other cases (OpenRegister), and the sources integriq connects
  (SharePoint, Teams, Outlook mail).
- Results are listed per source with name, location, date and a snippet.
- The handler picks results and adds them to the case. Each lands in the case
  folder as a file, so it becomes a document on the case and an outstanding item
  for assessment by the rules that already hold.
- Each added document records where it came from: the source, its location and
  the search terms, so the decision can account for the search.

## What this change does not do

- It does not assess, redact or publish. Those steps exist.
- It does not index anything. It asks each source's own search at the moment
  the handler searches.
- It does not build the Microsoft Graph search. That is integriq's half, named
  below.

## Sibling halves

- **ConductionNL/integriq** owes a document search operation over Microsoft
  Graph (`/search/query` across drive items, chat messages and mail) behind its
  credential broker, answering name, location, date, snippet and a fetch
  handle, and a fetch by that handle. Its nearest open change,
  `connectors-sharepoint-publication-intake`, adds a Graph adapter that lists
  and fetches SharePoint documents (`SharePointOnlineAdapter::listDocuments`,
  `fetchDocument`) and does not search. To be specified in integriq.
- **Nextcloud server** provides the unified search this change calls for files.
  Nothing is asked of it.

## Capabilities

- Modified: `woo-case-type`: three requirements added for the search, the
  adding and the record of provenance.

## Impact

`src/manifest.json` (`#CaseDetail` header action), one dialog under
`src/dialogs`, one controller and service pair under `lib/` for the integriq
dispatch and the add, a `provenance` block on the document projection. No
change to the Woo lifecycle.
