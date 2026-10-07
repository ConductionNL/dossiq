# Design: handing-a-case-over

## D-1. The internal handover is the federated one with the boundary removed

`CaseTransferService` already carries the hard parts: an initiate, an
accept, a reject with a reason, an idempotency key and a custody audit
trail. What it assumes is two organisations.

So the internal handover is the same act with a team as the counterparty
instead of a remote instance, writing the same `casetransfer` record with
the internal team in place of the target organisation. One record shape
means one answer to "where has this case been", which is the question an
archivist and a bezwaarcommissie both ask.

Building a second mechanism beside it would give two custody trails that
disagree, and ADR-011 exists to stop exactly that.

## D-2. A handover keeps the identity, or it is not a handover

OTOBO and Request Tracker both move the ticket rather than closing it and
opening another (`_round4/discovery/candidates.json`, C-case-core-44,
`case-core.tsv:12`).

The case keeps its number, its history, its documents and its running
terms. Nothing is recreated. A handover that made a new case would restart
the Awb clock, which is not a transfer, it is a way to hide a late case.

The receiving team may refuse it back with a reason, and the refusal is
recorded on the same trail. A handover nobody accepted sits with the
sender, visibly, rather than vanishing into a team that never looked.

## D-3. A doorzending tells the applicant

Awb 2:3 is a transfer with an obligation to tell the sender. The lane's
clause says so, and `inbound-mail-filters` REQ-IMF-03 already carries the
mail-level half for a message that was never a case.

So the handover declares whether it is a doorzending. Where it is, the
applicant is told, through the declared moments `ontvangstbevestiging`
builds, naming where the case went. Where it is a purely internal move
between two teams of the same bestuursorgaan, nobody outside is told,
because that would be noise about an internal arrangement.

## D-4. The second seat is a role on the case, not a second assignee field

OTOBO carries `ticket.user_id` and `ticket.responsible_user_id`
(`case-core.tsv:10`), and xxllnc calls the pair behandelaar and
casemanager.

dossiq already has a `people-on-the-case` surface and a `roleType`
vocabulary with a `coordinator` value. So the coordinator is a role
binding on the case, not a second column beside `assignee`, which keeps
the party model one mechanism and lets a case type add a third seat later
without a schema change.

`assignee` stays the handler, because everything from My Work to the queue
already reads it and a rename would be a fleet-wide break for no gain.

## D-5. The coordinator is a requirement a case type may state

The clause is precise: "the Awb answer is signed by the second". So a case
type may declare that a coordinator is required before the besluit is
signed, and the signing act refuses with the rule named when the seat is
empty.

A case type that does not declare it never asks for one. Most cases have a
handler and nothing else, and a product that insists on two people for a
melding openbare ruimte is a product nobody uses.

## D-6. Externally homed is a declaration on the case, and it is honest

PinkRoccade's claim is an architecture: the zaak lives centrally, the work
happens in the specialist application (`case-core.tsv:29`).

dossiq's honest version is a declaration: this case is homed in
application X, its identifier there is Y, and here is the link. The case
still carries its type, its status, its terms and its parties, because
those are what the central list is for. What dossiq must not do is pretend
to hold the work: a case homed elsewhere shows where the work is and does
not offer to do it.

The connector that keeps the two in step is integriq's. The facade a
specialist application reads the case through is openregister's. dossiq
declares and neither builds.

## D-7. The leaver handover is one act over everything, and it is recorded

Nextcloud Deck does it in one call and one occ command
(`access-and-privacy.tsv:72`). The clause names the reality:
"uitdiensttreding is a Tuesday afternoon and today it is a database
query".

So one act takes everything one person holds, cases where they are the
handler, cases where they are the coordinator, open tasks, and drafts, and
moves it to a named person or team. It is previewed before it runs,
because a handover of two hundred cases to the wrong person is worse than
the query it replaces.

It records who ran it, when, what moved and where from, so the work of a
person who has left is traceable afterwards. Drafts move too: a draft is
private to its author, and an author who has left leaves it unreadable
forever otherwise.
