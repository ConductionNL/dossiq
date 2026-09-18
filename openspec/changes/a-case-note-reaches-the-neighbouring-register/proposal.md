---
kind: config
depends_on: []
---

# Proposal: a-case-note-reaches-the-neighbouring-register

Competitor gap scan of 2026-09-18, pack b, row 6.14 "Notes synced to an
external case register". Scored no. Owner dossiq. Size S.

## Why

A note written on a case that lives in a neighbouring register stays here,
and nobody is told.

`lib/Service/External/Zgw/ZgwExternalAdapterInterface.php` declares exactly
two pushes: `submitZaak` and `submitDocument`.
`lib/Service/Stuf/StufOutboundTransport.php` carries the StUF-ZKN leg. No
caller pushes a note, and no schema on either side carries one.

integriq closed the connector half today. `zgw-connectors-for-dossiq`
(integriq#2070) ships six packaged sets, named in its own proposal:
`zgw-zaken`, `zgw-documenten`, `zgw-catalogi`, `zgw-besluiten`,
`zgw-objecten` and `zgw-notificaties`. Klanten and Contactmomenten are out
of scope there by design and belong to `vng-klantinteracties-adapter`. None
of the six carries a note, and none should: ZGW has no note resource.

So the hole is not a missing connector. It is that nothing has decided what
a note is, on the wire, and dossiq is the app that owns the note.

## What changes

A note is pushed as a `zaakinformatieobject` of a reserved
informatieobjecttype, over the `submitDocument` path that already exists.

That is a decision, and here is why it is the right one. A note has an
author, a moment, a body and a case, and so does a ZGW document; the
receiving register can read it, file it and show it without knowing dossiq
exists. The alternatives are worse. Appending notes to `zaak.toelichting`
overwrites one note with the next and loses the author. Waiting for the
Klantinteracties route makes a note about a case into a note about a
person, which it is not. Inventing a private extension gives a payload only
dossiq can read, which is the opposite of what a neighbouring register is
for.

Two rules come with it:

- Only a note that is not internal travels. `timeline-entries-default-internal`
  already decides which side of that line a note is on, and the default is
  internal, so nothing leaves by accident.
- A push that fails is visible on the case. A note that stayed home while
  the case says it synced is worse than a note that never tried.

## Ownership

dossiq owns the note, the internal-or-not decision and the mapping to a
document envelope. integriq owns the transport, the credentials and the
version translation, through the sets it shipped. The receiving register
owns what it does with the document once it has it.

## The question this leaves open

The reserved informatieobjecttype needs a name and a selectielijst
position, and that is a records-management choice rather than a code one. A
note filed as a document inherits a retention term, and the term for a
working note is not the term for a decision letter. Raised with the report
of this scan.

## ADRs

- Company ADR-022: the transport is integriq's, the mapping is dossiq's.
- Company ADR-102: a failed push fails loudly on the case, never silently.

## Capabilities

- Modified: `zgw-api-mapping`: a case note leaves for the neighbouring
  register as a document, or says why it did not.

## Impact

`lib/Service/External/Zgw/` (the note envelope and its caller);
`lib/Service/Timeline/` (the internal-or-not read);
`lib/Settings/register.d/` (the reserved informatieobjecttype);
`src/manifest.json` (the not-synced marker on the note).
