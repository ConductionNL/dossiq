# Design: portal-case-documents

Read at dossiq development `12b622c7d` and portaliq development `4176916`.

## Context

- A document on a dossiq case is an `informatieobject`
  (`register.d/70-document-zaakdossier.json`) joined to the case through a
  `zaakinformatieobject` (`case`, `informatieobject`). Its bytes live in the
  CASE object's folder (`DocumentRecordStore::storeFileOnObject()`), and the
  record keeps the Nextcloud `fileId`.
- A decision (`decision.case`) links its document through `decisionDocument`
  (`decision`, `document` as a uri whose last segment is the informatieobject
  uuid).
- portaliq `PortalCaseDocumentReader::wellFormed()` keeps an entry only with
  `id`, `title` and `file: {register, schema, id, fileId}`; `kind: decision`
  is the decision, anything else a document; `mimeType` (string) and `size`
  (int) are kept when present. `PortalFileReader::streamFile()` finds the file
  by `fileId` in the folder of the object `file` names.

## D1. The rule

A document reaches the resident when its `status` is `final` or `archived`
(an archived document was final first), its
`vertrouwelijkheidaanduiding` is `openbaar`, `beperkt_openbaar` or
`zaakvertrouwelijk`, and it is either linked by a decision on the case or has
`direction: outgoing`. An incoming letter can be a third party's (a
neighbour's zienswijze), and `intern` and stricter stay inside.

## D2. The read runs as the system, for one case

Portaliq calls the method after proving the case is the resident's, in a
request with no Nextcloud user. The service reads through
`ObjectService::runAsSystem()`, filters every search on the case id, and
re-checks the case id on every returned row.

## D3. The file reference names the case

`file` is `{register, schema: case_schema, id: caseId, fileId}`, because that
is the folder the bytes are in.
