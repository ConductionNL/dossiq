# Design: ontvangstbevestiging

## D-1. The trigger is a listener, not a workflow step

Request Tracker declares it once, as a scrip on create. Freescout passes
this candidate with no phases at all. Both prove the same thing: an
acknowledgement is a property of receiving something, not a step in a
process.

Putting it in a workflow means every case type has to remember it, and a
case type that forgets breaks a statutory duty silently. So it hangs off
the same `ObjectCreatedEvent` on the `case` schema that
`DeadlineCaseCreatedListener` already watches, and it is declared on the
case type rather than drawn in a process.

## D-2. It fires on an electronic submission, and it says which ones

Awb 4:3a is about electronically submitted messages. A case a handler
types at the balie does not owe one, and sending it anyway mails somebody
about a conversation they just had in person.

So the case type declares which intake channels owe an acknowledgement.
The default is every electronic channel: the portal, mail and an API
intake. A case created by hand owes none unless the case type says
otherwise.

## D-3. The failure is on the case, not in the log

`DeadlineCaseCreatedListener` already carries the lesson in its own
comments: a refusal that was invisible at the default loglevel hid a
fleet-wide key mismatch, so the missing term is now a warning and not a
debug line.

An acknowledgement that failed to send is worse, because it is a statutory
duty that somebody must now perform by hand. So it is a visible state on
the case with a retry, not a log line. A case whose acknowledgement never
went out is a case a handler can find.

## D-4. Sending never blocks the case

`TermijnNotificationService` already queues through `IJobList` so an SMTP
failure does not block the lifecycle operation. That stays. Creating a
case must not fail because a mail server is down, and a duty deferred by
four minutes is met while a case that failed to be created is not.

## D-5. What the message may quote is the case type's decision

D16 put the flag on the field: whether the citizen may see it. The
acknowledgement quotes back what was received, so it must read the same
flag as the portal does, from the same place.

Otherwise the acknowledgement becomes a second, quieter definition of what
the citizen may see, and the first time they diverge is a data-protection
incident rather than a bug.

## D-6. Content on the platform is a channel choice, not a template

C-communication-62 is the Berichtenbox pattern: tell them a message is
waiting, keep the content where it is. That is not a second template. It
is the same message with its content withheld, chosen per case type,
because a municipality that decides personal data does not go in e-mail
decides it once and not per template.

## D-7. The citizen's channel choice wins where it exists

C-communication-55 is the preference. It is read where the citizen has
one, and the case type's default applies otherwise. dossiq holds neither
the preference store nor the routing: openregister's notification dialect
(ADR-031) does, and dossiq ships the templates and the declaration.

## D-8. One record, so the question has one answer

"Did we confirm receipt, when, to whom, and by which channel" is asked by
an auditor, by a klachtbehandelaar and by the citizen. It is recorded once
on the case as an outbound communication, and cluster 23's log will render
that record rather than build a second one.

## D-9. The same declaration carries the other moments

C-communication-54 asks for automatic mail at configured moments, and its
clause says why one of them is not like the others: "'we need something
from you' as a distinct notification from 'your case moved' is the
difference between informing and chasing".

So the case type declares a list of moments, and the acknowledgement is
the first entry with the statutory flag on it. One mechanism, one place to
look, and the statutory one is marked so it cannot be quietly removed.
