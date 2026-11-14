# Proposal: stuf-writes-through-integriq

## Why

Decision 206 (12 Oct 2026): another system may write cases and documents into dossiq over StUF, but
only through integriq and authenticated. Until now dossiq refused every zakLk01 and edcLk01.

## What changes

- Integriq authenticates the sender against its StUF-ZKN source and maps the message onto a generic
  operation: `create`, `update` or `add-document`. Dossiq reads the operation from integriq's event.
  It parses no StUF and has no StUF write route of its own.
- `ConnectedSystemCaseWriter` carries the operation out as the background service account, with the
  register's access rules on. It is generic: nothing in it names StUF or a procedure.
- A `stufEndpoint` opts in with `acceptsWrites` (off by default). Its `zaaktypeMappings` decide which
  case type an incoming case opens on, and the new `documentTypeMappings` decide the document type.
  An unmapped zaaktype or document type is refused.
- A case a connected system opened keeps `originSystem` and `originIdentifier`, so the system's later
  messages find it by the identifier it gave. Any other case is found by its dossiq case number.
- An update changes the title, the description and the planned end date. Type, status, handler and
  the dates a term hangs on stay dossiq's.
- A document takes the same screened upload path as a handler's upload.

## Not changed

The four forwarding routes (`/api/stuf/cases`, `/persons`, `/zaken`, `/personen`) still hand queries
to integriq for one release. Integriq refuses a write that arrives through them.
