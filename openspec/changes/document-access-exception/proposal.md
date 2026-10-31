# A document can carry an access exception: only named people and groups see it

## Why

Ruben decided on 24 October: build the per-document access exception the board draws. DqDocumentenBeheer's
"Who can see this" card shows, for one document, the default ("Everyone on the case"), an exception ("Only Pieter
Jansen and the Woo team") and a reason ("Commercially sensitive prices"), with a "Change exception" button. Today a
document carries only a confidentiality level, which compares a clearance and says nothing about named people.

## What changes

- **Data.** The informatieobject gets an optional `accessException`: `users[]`, `groups[]`, `reason`, `setBy`,
  `setAt`. A reason is required when an exception is set. `accessExceptionHistory` keeps every set, change and
  removal: who, when, the old and the new exception, and the reason. Schema minor bump (1.4.2 to 1.5.0).
- **Who reads a document with an exception.** A listed user, a member of a listed group, and whoever may change a
  case the document is on: the case's handler (`assignee`) and administrators (CaseAccessGuard::hasCaseMutationAccess).
  Decision: the handler keeps access, so nobody locks a case's own handler out of its documents. Everyone else,
  including other members of the handling team, gets nothing.
- **Enforced before any read, on every path.** The one dossiq read rule, InformatieobjectAccessGuard, now asks the
  exception as well as the clearance. Every path that already went through it follows: the case's document listing
  (and so the Files tab rows, groups and counts), document detail and properties, download by file and by ZGW uuid,
  the case ZIP, the linked documents of another case, bulk actions and approval markers. The resident portal never
  shows a document with an exception.
- **The file itself.** The document's file lives in the case's folder in OpenRegister, which any reader of the case
  could list and download. Setting an exception also restricts that file in OpenRegister (openregister
  `per-file-read-restriction`), to the same users and groups; dossiq admits the case's handler there through
  `FileRestrictionAdmitEvent`. So the Files tab's listing, OpenRegister's download, preview, ZIP and file search
  leave the file out for everyone else. Without an OpenRegister that can restrict files, setting an exception is
  refused (503) rather than half applied.
- **Setting it.** `PUT /api/informatieobjecten/{id}/access-exception` (`users`, `groups`, `reason`) sets or changes
  it, `DELETE` removes it. Only for a caller who may change every case of the document (DocumentCaseWriteGuard,
  dossiq #4624), checked before anything is read; the caller must also be able to read the document.
- **UI.** The "Who can see this" side card shows the default, the exception and the reason; "Change exception"
  opens DocumentAccessExceptionDialog (people and group picker, reason).

## What does not change

- A document without an exception is decided exactly as before.
- The confidentiality level stays, and still applies to everyone (also to listed people).

## Raw OpenRegister access (review, 25 October)

- **Reads.** The informatieobject schema carries its own authorization (the SharePrincipalDeriver pattern):
  dossiq-medewerkers read a document only where `restricted` is false or absent; everyone else reads it only when
  listed in the derived `accessUsers` (the exception's people plus the handlers of its cases) or `accessGroups`.
  The same rule applies to update and delete. So OpenRegister's lists, totals and facets agree with dossiq's own
  rule, and every dossiq read that goes through OpenRegister as the caller (also the beroep export listing) follows.
- **Writes.** `accessException`, `accessExceptionHistory`, `restricted`, `accessUsers` and `accessGroups` are
  writable only by dossiq-background-service. The writer saves as that account after its own checks; without a
  configured account the set is refused (503). OpenRegister administrators bypass property rules, as everywhere.
- **Handler changes.** When a case's `assignee` changes, its restricted documents' `accessUsers` are recomputed.
- **Copies.** A redacted copy and a signed version carry the original's exception and their files are restricted.
- **Woo.** A publication with a document under an exception is refused (`documents_restricted`).

## Known limits

- Native Nextcloud Files/WebDAV: see openregister per-file-read-restriction; object files live in OpenRegister's
  own account and dossiq shares no case folder natively.
- The ZGW DRC API reads the ZGW `document` model, which carries no exception.
