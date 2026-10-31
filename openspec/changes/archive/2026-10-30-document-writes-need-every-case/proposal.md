# A document write needs the change right on every case of the document

## Why

PATCH informatieobjecten/{id}, /status, bulk/status and bulk/metadata only
checked that the caller may READ the document. Anyone who could read a
document could change its metadata or status, also on a case they may not
change. Decision (coordinator, 24 Oct): a write to a document requires the
change right on every case it belongs to.

## What changes

- DocumentCaseWriteGuard: the cases of a document (its joins, read
  regardless of the caller, only the ids), each checked with the change right
  an upload uses (DossierUploadHandler::hasCaseUploadAccess); the first case
  refused is named.
- The four routes check it BEFORE the document is read or written; 403 names
  the document and the case. A bulk write is refused whole when one document
  fails, naming it. A document on no case keeps the read clearance rule.
