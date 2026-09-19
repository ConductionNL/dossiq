---
kind: code
depends_on: []
---

# Proposal: one-timeline-on-the-case

Competitor gap register, row 6.4 "Unified communication timeline per case
(notes, mail, messages)" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence). Rated no for dossiq, the nearest thing
being the audit sidebar, which carries status changes and nothing a
citizen ever said. Neighbouring rows: 6.2 "contact moments" (an API with
no page of its own) and 6.15 "visibility per entry", whose platform half
is openregister #3711.

## Why

A handler who wants to know what has been said about a case reads four
places. Notes are a tab. Logged calls are another tab, over the
`contactmoment` schema. Mail sits in a third, through the mail leaf.
Berichtenbox messages and the acknowledgement of receipt are written into
fields on the case and read nowhere. Nothing puts them in one order, so
"what happened on this case, newest first" is a question dossiq cannot
answer, and the citizen portal cannot be handed a case history at all.

Each of those logs is also its own store. A fifth writer means a sixth
place to look.

## What is actually there

Read against `development` at `43150ddf`.

- `case-notes-panel` renders `CaseNotesTab`, which is the library's
  `CnNotesTab` over OpenRegister notes.
- `case-communication-panel` is an `object-list` over the `contactmoment`
  schema, filtered on `contactmoment.case`.
- `case-email-panel` renders `CaseEmailTab` over the mail leaf.
- `IntakeLog::record()` writes an inbound-mail log row per message
  (`inbound-mail-filters`).
- `AcknowledgementService::acknowledge()` appends to the case's own
  `outboundCommunications` list.
- `BerichtenboxService::sendMessage()` writes a message object.
- `CaseEmailService::sendEmail()` records the sent mail as a case
  document.

Six writers, five stores, one chronology nowhere.

## What changes

openregister #3762 (`timeline-entries-are-records`) made a timeline entry
a record with a declared kind, a visibility, a pin, a follow-up, a raw
source and a search across objects. dossiq consumes it rather than
building a sixth store.

- dossiq declares its kinds once per instance, in a repair step: a
  contact moment (with channel and direction), inbound mail, outbound
  mail, a portal message, an acknowledgement of receipt, a status change
  and a term event.
- Every writer above records an entry of its kind beside the record it
  already writes, carrying the dossiq record id in `fields` so the
  detailed record is one hop away.
- The case page gets one Timeline tab: the entries newest first, pinned
  ones on top, filtered by kind and by visibility, with pin and
  follow-up done. The audit sidebar stays where it is, for status
  changes.
- A note written from the Timeline tab can be written on several related
  cases at once, through `relatedObjects`.
- The standard notes are administered text blocks rather than retyped.

## Ownership

dossiq consumes openregister `timeline-entries-are-records` (merged
2026-09-15, #3762). Visibility defaults and the applicant-facing toggle
are `timeline-entries-default-internal` in this repo, row 6.15. The
portal read is portaliq's, over `/api/timeline/search?visibility=public`.

## ADRs

- Company ADR-022: apps consume OpenRegister abstractions.
- Company ADR-080 D2/D3: an app calls OpenRegister in process, never
  over a self-addressed HTTP request.
- Company ADR-054: a public surface shows only what is declared public.

## Capabilities

- Modified: `case-history-surface`: the case carries one timeline beside
  the change history, and every communication writer records on it.

## Impact

`ContactMomentService`, `IntakeLog`, `AcknowledgementService`,
`CaseEmailService`, `BerichtenboxService`, the case manifest, the widget
registry, one new repair step and one new service.

## Deliberately out of scope

- ~~The status-change and term-event writers.~~ Landed after the lanes
  that owned `StatusTransitionService` and `TermijnService` merged, as
  REQ-TL-15 and REQ-TL-16. The audit sidebar still holds the change
  history; the timeline now holds the same moves beside the
  communications, which is the order a handler reads.
- A `notitie` kind. OpenRegister projects every note written through
  `/notes` as an entry with no kind, so a declared `notitie` kind would
  split one log into two filter buckets that mean the same thing. The
  reader labels an entry with no kind as a note.
