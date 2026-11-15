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

## D-6. The declaration is enforced per schema, and that is a real limit

OpenRegister's `SubstantiveChangeEvaluator` resolves
`x-openregister-read-state` from a `Schema`, never from an object. So the
enforced floor is the block on the `case` schema, and it carries the whole
vocabulary a case type may name. `caseType.unreadTriggers` is the authoring
surface over that vocabulary: it is read by `UnreadTriggerService`, rendered on
the case type, and warned about on publication when it names something nobody
recognises or drops the status.

What it cannot do today is narrow the evaluator for one case type. A case type
that leaves `assignee` out still has its cases marked unread when an assignee
changes, because the schema block watches it for every case type at once.

This is written down rather than papered over. dossiq could fight it by
re-marking objects read after somebody else's write, and that would be a second
read state under another name, which D-1 exists to prevent. The ask belongs in
openregister: resolve the annotation per object, so a leaf app can point at a
declaration the object itself carries. Recorded in tasks 4.2.

## D-7. The badge is beside the tabs, not on them, and that is the library's half

`CnTabsWidget` renders a label and an icon per tab. It takes no badge and emits
no tab change, so a sibling widget can neither decorate a tab nor learn that one
was opened. Both are one small change in `@conduction/nextcloud-vue`, and they
belong there because every list and detail page in the fleet wants the same
affordance.

So D-3 ships as a strip directly above the tab bar: it names each panel holding
something unseen and how much, and its button makes the same write opening the
tab will make. The strip goes the day the tab can carry the count.
