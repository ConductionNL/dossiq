# Design: one-timeline-on-the-case

## D1 One store, and it is not ours

OpenRegister owns the entry. dossiq writes through it and reads through
it, and adds no table, no schema and no mapper of its own. The rule the
change is measured against: after this change dossiq still has exactly
one place that knows how to write a timeline entry.

That place is `OCA\Dossiq\Service\Timeline\CaseTimeline`. Every writer
calls it. It resolves OpenRegister's `TimelineWriteService` lazily
through the container, the same way `SettingsService::getObjectService()`
already does, because OpenRegister is an optional runtime dependency and
an instance running an older one must keep sending mail.

## D2 In process, never over HTTP

ADR-080 D2/D3 forbids an app calling its own instance over HTTP. The PHP
writers therefore use `TimelineWriteService::write()` and
`TimelineKindService::declareKind()` directly. The browser is the one
caller that uses the REST routes, which is what they are for.

## D3 The write fails soft, always

A timeline entry is a record of something that already happened. The mail
was sent. The call was taken. Losing the entry is bad; refusing the act
because the entry could not be written is worse, and on the
acknowledgement path it would break a statutory duty (Awb 4:3a) to keep a
log tidy. `CaseTimeline::record()` therefore returns the entry id or the
empty string, logs its own failures, and throws nothing at its callers.

The mirror risk is a write that fails silently forever and nobody
notices. It is logged at warning with the case id and the kind, and the
Timeline tab shows an error rather than an empty list when the read
fails, so an outage and a case nobody has said anything about do not look
the same.

## D4 Which kinds, and which carry a follow-up

`carriesFollowUp` opens a follow-up on EVERY entry of that kind. So only
a kind where every single entry genuinely needs an answer may carry one.

- `contactmoment`: channel, direction, the dossiq record id. No
  follow-up: most logged calls are answered while the handler is on the
  phone, and opening a task on each would make the follow-up count
  meaningless.
- `mail-inkomend`: sender, subject, the intake log id, the intake
  outcome. Carries a follow-up. A message that reached a case is open
  until a handler says otherwise, which is exactly what the register's
  row asks for.
- `mail-uitgaand`: recipient, subject, the recorded document id.
- `portaalbericht`: subject, the message id, the delivery status. The
  BSN the dispatch takes is deliberately NOT a field: the entry is
  public, and the recipient's identifier has no business on a surface
  the recipient is not the only reader of.
- `ontvangstbevestiging`: channel, recipient, template, sentAt.
- `statuswijziging` and `termijngebeurtenis`: declared, unwritten here.
  See the proposal's out-of-scope note.

## D5 Which entries are public

Internal is the default, per `timeline-entries-default-internal`. An
entry is public only when the thing it records already left the counter:
an outbound mail, a portal message, an acknowledgement of receipt. An
inbound mail entry is internal even though the citizen wrote it, because
the entry carries the filter verdict and the authentication results,
which are about the instance rather than about the sender.

## D6 The tab, and what stays

The Timeline tab is one chronological read, not a replacement for the
three tabs beside it. Notes, Communication and Email each remain the
place to DO that one thing; Timeline is the place to see the order. The
audit sidebar stays for status changes, which is the rule
`case-history-surface` already set.

Registered as a widget TYPE (`case-timeline-pane`), because a tab child
resolves from `cnRegistry[widget.type]` and a `component:`-only entry
draws an empty panel and logs nothing. That failure has been shipped
twice on this page.

## D7 Related cases

A note that belongs on several cases is written once with
`relatedObjects`, which resolves every object before writing any, so a
case the author may not write on is a 403 naming it rather than a note
that landed on two of the three. `ContactMomentService` already keeps a
`relatedCases` list for the KCC voorblad, and uses the same path.
