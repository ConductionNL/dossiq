# Design: document-correspondents

## D-1. A correspondent is a party, never a name

`dispatch.contactPersonName` has been in the register since the ZGW import
and nothing has ever written it. That is the shape this change refuses.

A typed name cannot be counted, cannot be filtered, and goes stale the day
the party is corrected: two letters to the same person read as two people.
So `informatieobject.sender` holds one party and
`informatieobject.recipients` holds a list of them, each as the uuid of a
party that is already ON THIS CASE. The roles are the case schema's own
`afzender` and `geadresseerde`, which
`gemachtigde-role-on-every-case-type` put on every case type, so the party
a document names renders in the People tab under the label it already has.

A link written before the party model carries a `contactUid` and no
`partyUuid`. Those are accepted too, by the same rule: the value must be
one the parties listing answers with. What is refused is a value the
listing does not know, and it is dropped rather than stored, because a
correspondent nobody can open is worse than none.

## D-2. The case's own parties are the whole vocabulary

`DocumentCorrespondents` takes the parties listing and a submitted value
and answers with what may be stored. It resolves three ways, in order: the
party uuid itself, the contact uid, and the e-mail address. The third is
what lets the mail intake write a sender from a From header without a
picker, and it is an exact, case-insensitive match on the address, never a
guess at the display name.

Nothing here writes a party. A From address nobody on the case holds
leaves `sender` empty and `direction: incoming`, and the handler picks the
party in the properties dialog. Creating a party from an inbound address
is `contacts-domain`'s act, not this one's, and doing it here would fill
the case with a party per stranger who mails it.

## D-3. The dispatch record is the per-send row

The first draft retired `dispatch`. It is kept, and gains its first
writer. `informatieobject.sender` and `recipients` are what every surface
reads, because they sit on the document a row is already showing. The
dispatch row is what carries the date of one send to one party, which a
list on the document cannot: a letter sent twice to the same addressee is
one recipient and two dispatches.

The two are written together and read apart. `CorrespondentWriter` writes
the fields and the rows in one call, so there is no path that sets one
without the other.

## D-4. Direction decides the role, and the role never contradicts it

A document is incoming, outgoing or internal. Incoming names a sender,
outgoing names recipients, internal may name either and usually names
neither. The rules refuse the combination that reads as a bug: a sender on
an outgoing document, recipients on an incoming one. Refuse means drop and
say so in the return, not throw, because these arrive from an upload that
would otherwise lose the file with the correction.

## D-5. The columns wait on nextcloud-vue, the values do not

`files-browser-columns` (nextcloud-vue, row 4.8) is how the Files tab shows
an extra column. The manifest declares Sender and Recipients now and the
browser ignores what it cannot read. Meanwhile the dossier listing carries
both fields resolved to names, the properties dialog edits them, and the
People tab lists a party's documents, so the information is on screen in
three places before the column exists in one.
