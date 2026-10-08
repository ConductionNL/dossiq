---
kind: config
depends_on: [contacts-domain, document-correspondents]
---

# Proposal: the-contact-360-shows-documents

Competitor gap scan of 2026-09-18, pack b, row 5.4 "Contact 360 view
(cases, documents, communication)". Scored partial. Owner dossiq. Size S.

## Why

The row names three things and dossiq shows two.

`ContactDetail` and `OrganisationDetail` carry three widgets each:
`contact-card`, `contact-cases` over `case` filtered on `requester`, and
`contact-moments` over `contactmoment` filtered on `contact`. Cases are
there. Communication is there. Documents are not, on either page.

So a KCC agent with a caller on the line can see which cases that person
has and what was said to them, and cannot see the letter the gemeente sent
them last week. That letter is the most common thing a caller rings about.

The data is there and is already filterable. `document-correspondents` put
`sender` and `recipients` on the `informatieobject` schema, each holding a
party rather than a typed name, precisely so a correspondent can be counted
and filtered. Nothing reads them from the contact's side yet.

Two competitors show it. Zaaksysteem's ContactBeeld has a Documenten
reading beside Zaken and Communicatie; OpenCase's citizen card has a
Documents tab. openregister#3893 `contacts-leaf-cases-panel` is merged but
answers a different question: it is the reverse lookup for a CardDAV
contact on the case page, keyed on an address book uid, not a panel on
dossiq's own `brpPerson` and `kvkCompany` pages.

## What changes

- `ContactDetail` and `OrganisationDetail` gain a Documents widget over
  `informatieobject`, holding what this contact sent and what was sent to
  them, newest first, with the direction on every row.
- A row deep links to the case the document is on, because a document out
  of its case is a file without a reason.
- A document this reader may not read is counted and never named, which is
  the rule `ContactCasesPanel` set in openregister#3893 and the reason it
  gave: dropping it answers "two" where the truth is "five".
- Empty is a sentence, not a blank panel.

## Ownership

dossiq. The pages are dossiq's, the schema is dossiq's and the two fields
the filter reads are dossiq's. Nothing new is asked of openregister beyond
the object listing it already answers. The contact's own record stays where
it is: `brpPerson` and `kvkCompany` are read, never written, and the BRP
and KvK refresh is integriq's under `registry-subscription-connector`.

## ADRs

- Company ADR-022: the listing is OpenRegister's objects endpoint, not a
  dossiq wrapper.
- Company ADR-062: the panel is a declared widget, not a custom component.

## Capabilities

- Modified: `kcc-klantcontact-integratie`: the contact view answers all
  three questions a caller asks.

## Impact

`src/manifest.json` (two pages, one widget each, one layout row each).
