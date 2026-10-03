# Design: live-conversation-on-the-case

## D-1. Generalise the hearing, do not build a second calling feature

`HearingService` already opens a Talk room through `OCP\Talk\IBroker`,
schedules it, records attendance and files minutes. It is written for the
bezwaar hoorzitting.

So the change is a lift: the same act, started from any case, with the
hoorzitting as one configured use of it rather than the only one. A second
mechanism for "a call on a case" beside the hearing would give two
attendance records that disagree, and the bezwaar one is the one a
commissie relies on.

Huly hosts calling itself (`_round4/discovery/candidates.json`,
C-communication-31, `communication.tsv:29`), and dossiq does not need to:
Nextcloud Talk is already in the platform, which is the difference between
a fleet app and a product that must ship everything.

## D-2. The record is on the case, and the media is a document

A call whose only trace is a Talk room is a call the archive never sees.
So the case records that a conversation happened, when, who joined and for
how long, and what it produced becomes a case document through
`documents-live-on-the-case`.

The visibility rules are the case type's, per D16. A recording of a
hoorzitting is not automatically something the citizen may download, and
deciding that per case type is the only way a gemeente can run this
lawfully.

## D-3. A voice note is the same act with no second party

The toezichthouder's constatering ter plaatse is a photo and a voice note
(`communication.tsv:69`). It is not a call, and building it as a separate
uploader would give a third media path.

So it is a capture attached to a case or a task, landing as a case
document under the same rules as D-2. Where the platform offers a
recorder, dossiq uses it; where it does not, the affordance is absent
rather than replaced by a dossiq recorder.

## D-4. Major is a declaration on the case, and the channel follows from it

Jira Service Management marks an incident major and then opens the channel
and pulls the responders in (`case-core.tsv:15`). The order matters: the
declaration is the act, the channel is a consequence.

So `case.isMajor` is set by a permissioned act, and declaring it opens one
channel and notifies the responders the case type names, over the
notification dialect per ADR-031. A case type whose responders cannot be
resolved refuses the declaration, per ADR-102, because a crisis channel
with nobody in it is worse than no channel.

One channel per case, never one per person who wants one. The clause is
fifteen minutes; two channels is how a calamiteit ends up half-coordinated
in each.

## D-5. The channel closes with the case and its content stays

A working channel that outlives the case becomes a place people talk about
a closed case with no record. So closing the case closes the channel, and
what was said is filed on the case as the conversation record, under the
case type's visibility rules.

## D-6. Dictation is hermiq's, and dossiq adds no voice path

D13 was taken as option 1: hermiq owns the assistant, dossiq declares the
tools and the rights. A dossiq voice path into dossiq's own tools would be
a second assistant in the fleet, which is what option 2 was rejected for.

So C-communication-67 is named as hermiq's and the only dossiq work is
making sure the declared tools carry no assumption of typed input, so a
dictated call reaches them exactly as a typed one does. The register
already says the same about row Q9.15: "every case action is already a
curated tool (hermiq-ai-tooling); a typed command line would parse onto
them".
