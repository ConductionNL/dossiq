# Design: unread-state-on-the-case

## D-1. dossiq stores no read state

A per-user read marker on an object is openregister's, for the same reason
a grant is: every leaf app needs it and none should hold it. dossiq
declares and renders.

## D-2. What counts as a change is a case-type decision

A status change matters. A new document matters. A field an administrator
corrected in bulk does not, and treating it as a change lights up four
hundred rows on a Tuesday morning, after which nobody looks at the badge
again.

So the case type declares which changes make a case unread, with a default
that covers the status, the documents and the messages. A badge that
cries wolf is worse than no badge.

## D-3. The badge is on the tab, because that is where the work is

xxllnc puts it on the case's side menu, per section. A case that is
unread tells a handler to look; a case whose Documents tab is unread tells
them where. The second is the one that stops a document sitting unseen for
a week.

## D-4. Opening the work clears the notice

Dimpact ZAC clears a signalering when the user opens what it was about,
and the lane says nobody else in the corpus does. It is the rule that
makes a badge trustworthy: an alert that only clears by being dismissed
becomes a number people stop reading.

## D-5. Mark as unread is a real act, not a bug

OTOBO's `AgentTicketMarkSeenUnseen.pm` exists because a handler opens a
case, realises they cannot deal with it now, and wants it to look
untouched again. Read state is a working tool, not an audit record, and it
must be settable both ways.
