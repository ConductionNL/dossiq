## ADDED Requirements

### Requirement: a document can carry an access exception with a reason

An informatieobject MAY carry an `accessException` naming users and groups, with a required reason and who set it
and when. Every set, change and removal MUST be recorded in `accessExceptionHistory` with who, when, the old and the
new exception, and the reason.

#### Scenario: setting an exception records it
- GIVEN Pieter may change the case of "Offerte verlichting.pdf"
- WHEN he sets the exception to himself and the group woo-team with reason "Commercially sensitive prices"
- THEN the document carries that exception with setBy pieter and the time
- AND the history holds one entry: action set, by pieter, old empty, new that exception, the reason

#### Scenario: an exception without people, groups or reason is refused
- WHEN the exception names nobody, or has no reason
- THEN the answer is 400 and nothing is stored

#### Scenario: removing the exception records it
- WHEN Pieter removes the exception
- THEN the document carries none and the history holds an entry: action remove, the old exception, new empty

### Requirement: a document with an exception is readable only by its listed people, the case's handler and administrators

A document with an exception MUST be readable only by a listed user, a member of a listed group, or a caller who may
change a case the document is on (the case's handler and administrators). The confidentiality level still applies
to all of them. A document without an exception is decided as before.

#### Scenario: a reader of the case who is not listed
- GIVEN the exception names pieter and woo-team, and Anna reads the case but is in neither and is not its handler
- THEN Anna may not read the document

#### Scenario: listed people, the handler and an administrator
- THEN pieter, a member of woo-team, the case's handler and an administrator may read it

### Requirement: every path that returns the document or its file honours the exception

The exception MUST be applied before the document is returned, on every path: the case's document listing (rows,
groups and counts), document detail, download by file and by ZGW uuid, the case ZIP, linked documents, bulk actions,
approval markers and the resident portal. The document's file MUST also be restricted in OpenRegister to the same
users and groups, with the case's handler admitted, so OpenRegister's folder listing, download, preview, ZIP and file
search leave it out for everyone else.

#### Scenario: the case listing leaves the document out
- WHEN Anna lists the case's documents
- THEN "Offerte verlichting.pdf" is not in the rows, and the group counts and total do not count it

#### Scenario: detail and download answer 403
- WHEN Anna asks for the document, or downloads its file by file id or ZGW uuid
- THEN the answer is 403 and no bytes are sent

#### Scenario: the case ZIP leaves it out
- WHEN Anna downloads the case's documents as a ZIP
- THEN the document is not in the archive or its manifest

#### Scenario: the resident portal never shows it
- WHEN the portal asks for the case's published documents
- THEN a document with an exception is not among them, even when final and outgoing

#### Scenario: the file is restricted in OpenRegister
- WHEN the exception is set
- THEN the document's file is restricted in OpenRegister to the same users and groups with the same reason
- AND when OpenRegister cannot restrict files, setting the exception is refused with 503 and nothing is stored

#### Scenario: OpenRegister asks dossiq about the handler
- WHEN OpenRegister asks whether the case's handler may read the restricted file
- THEN dossiq admits them, and nobody else who is not listed

### Requirement: only a caller who may change every case of the document sets the exception

Setting, changing or removing the exception MUST be refused, before the document is read, when the caller may not
change one of the cases the document is on (DocumentCaseWriteGuard). The caller must also be able to read the
document.

#### Scenario: a reader who may not change the case
- WHEN Anna sets an exception
- THEN the answer is 403 naming the case, and nothing is read or written

### Requirement: the "Who can see this" card shows the exception and changes it

The Files tab's "Who can see this" card MUST show the default (the confidentiality level), the exception (the named
people and groups) and the reason, and offer "Change exception", which opens a dialog with a people and group picker
and a reason, saved through the endpoint above.

#### Scenario: the board's card
- GIVEN "Offerte verlichting.pdf" carries the exception pieter and woo-team with reason "Commercially sensitive prices"
- THEN the card reads Default, Exception "Only Pieter Jansen and woo-team" and Reason "Commercially sensitive prices"

### Requirement: OpenRegister's own API applies the same rule to the record

OpenRegister's object API (find, list, totals, facets, update, delete) MUST refuse an informatieobject with an
exception to everyone the exception does not admit. The informatieobject's own authorization admits
dossiq-medewerkers only where `restricted` is false or absent, and anyone listed in the derived `accessUsers` (the
exception's people plus the handlers of the document's cases) or `accessGroups`. The exception fields and the
derived lists MUST be writable only by the background service account; the writer saves them as that account after
its own checks. A change of a case's handler MUST refresh `accessUsers` of the case's restricted documents.

#### Scenario: staff who are not listed do not get the record from OpenRegister
- GIVEN "Offerte verlichting.pdf" carries an exception for pieter and woo-team
- WHEN Anna (dossiq-medewerkers) lists or reads informatieobjecten through OpenRegister
- THEN the record is not in the list or its total, and a direct read is refused

#### Scenario: a listed outsider reads the record but does not change it
- GIVEN Anna is listed on the exception but is not a handler of the document's cases
- WHEN she updates or deletes the record through OpenRegister
- THEN the write is refused; update and delete of a restricted document are for its cases' handlers (`accessHandlers`)

#### Scenario: a PUT that leaves out the protected fields keeps them
- WHEN a member of dossiq-medewerkers PUTs the record without `restricted` or the history
- THEN both are kept (openregister omitted-unwritable-properties-are-kept)

#### Scenario: a raw write of the exception is refused
- WHEN a member of dossiq-medewerkers writes `accessException`, `restricted`, `accessUsers` or `accessGroups` through OpenRegister
- THEN the write is refused

#### Scenario: the new handler of a reassigned case
- WHEN the case is reassigned from Hanna to Kees
- THEN the restricted document's `accessUsers` names Kees instead of Hanna

### Requirement: a copy of a document carries its exception

A redacted copy and a signed version of a document with an exception MUST carry the same exception: the copy's file
is restricted in OpenRegister to the same people and groups, and the copy's record gets the exception with a history
entry naming the original.

#### Scenario: the redacted copy
- WHEN a redacted copy of "Offerte verlichting.pdf" is recorded
- THEN the copy carries the exception and its file is restricted

#### Scenario: the signed version
- WHEN a signed version of the document is stored
- THEN its file is restricted to the same people and groups

### Requirement: a document with an exception is never published

A Woo publication MUST be refused while one of its disclosable documents carries an exception, naming the
documents, and nothing is sent.

#### Scenario: publishing with a restricted document
- WHEN the case is published and a disclosable document carries an exception
- THEN the publication is refused with `documents_restricted` and nothing is attached
