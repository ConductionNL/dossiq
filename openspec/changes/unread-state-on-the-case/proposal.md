---
kind: code
depends_on: []
---

# Proposal: unread-state-on-the-case

Round 4 discovery, cluster 62 "Per-user unread state and what clears it"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Six candidates, seven
passers, all seven driven, proving system OTOBO. Owner openregister, size
M, wave 1. This change is dossiq's half.

dossiq is `no` on all six. It is one of the seven clusters where every
member fails.

## Why

C-search-1's clause: "the cheapest way to see what moved overnight". A
handler opening a queue of four hundred cases has no way to tell which
three changed while they were away.

C-case-core-26's is the one that costs money: an unread badge on the
case's own tabs "is what stops a document sitting unseen on a case for a
week".

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-search-1 | should | no | a case carries a per-user unread state, shown in the list and settable back to unread |
| C-case-core-26 | should | no | an unread badge on the case's tabs when files await acceptance or messages are unread |
| C-communication-15 | should | no | a notification clears itself when the user opens what it was about |
| C-communication-6 | could | no | a case message is marked read or unread by hand |
| C-communication-18 | could | no | a notification is snoozed until a date or archived rather than only read |
| C-communication-61 | could | no | the notification list is filtered by what each notice is about, and one thread is marked read |

The proving passer, verbatim from the lane
(`_round4/discovery/candidates.json`, C-search-1, `search.tsv:11`):
"otobo: Ticket menu, Mark as unseen (AgentTicketMarkSeenUnseen.pm)". Two
driven passers, Frappe Helpdesk and OTOBO, and the lane's note: "Both are
the same read and unread marker seen from its two ends".

C-communication-15 is worth quoting because it is the one nobody else
does: "it is why the badge means something, and nobody else in the corpus
clears an alert by the work being done". One driven passer, Dimpact ZAC.

No candidate here is a `must`, so **D6 answered relevance-led** admits
none of them on relevance; they enter on the two-driven-passers bar, which
C-search-1 clears. The three `could` members have one driven passer each
and are carried only because they are the same mechanism rendered
differently, not because each earns a row. **D17 was answered for a broad
market**, and none of the twenty `not` candidates is in this cluster.

## The decision this rests on

None. `build-plan.md` names no decision for cluster 62.

## What dossiq does

- Renders the unread state on the case list, so what moved overnight is
  visible without opening anything.
- Renders it per tab on the case, so a document waiting on the Documents
  tab is visible from the case header.
- Offers mark as unread and mark as read, on a case and on a single
  message.
- Clears the notification when the handler opens what it was about, so
  the badge means something.
- Declares which changes make a case unread, per case type, so a
  mass-update of a field nobody reads does not light up four hundred
  rows.

## Ownership

openregister owns the per-user read state, what clears it, and its
storage. Its change is **to be specified in openregister, wave 1**;
`build-plan.md` proposes the slug `object-read-state`, and this proposal
records whichever slug that lane opens. nextcloud-vue renders it in the
list components. dossiq declares what counts as a change and renders the
case page's own badges.

The snooze and the notification-list filter, C-communication-18 and
C-communication-61, are the Nextcloud notification surface rather than
dossiq's, and they are named here so the openregister lane can decide
whether its change carries them.

## Capabilities

- Modified: `case-management`: a case knows whether you have seen it.

## Impact

`src/manifest.json` (the unread column on `#Cases` and `#Queue`, the case
tab badges), the `caseType` schema (what counts as a change), the case
header, Dutch and English strings. No PHP beyond the declaration.

## Out of scope

- The read state itself and what clears it. openregister, wave 1.
- The list column rendering. nextcloud-vue, cluster 58 and cluster 15.
- Notification preferences per user, per group and per template. Cluster
  10, openregister.
