---
kind: code
depends_on: []
---

# Proposal: ontvangstbevestiging

Round 4 discovery, cluster 32 "Acknowledgement of receipt, and what the
citizen is told" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Five candidates, eight
passers, six driven, proving system Request Tracker. Owner dossiq, size M.
Statutory: Awb 4:3a.

`found-and-lacking.md` puts it seventeenth in the twenty-five loudest
gaps and then says which of the twenty-five to do first: "Number 17 is the
one to fix first. Awb 4:3a owes every electronic request a confirmation of
receipt. It is a statutory duty, not a convenience."

## Why

Awb 4:3a: a bestuursorgaan confirms receipt of an electronically submitted
message. Not when it gets round to it, and not only when somebody
remembers to send a letter.

## What is actually there, which is not what the lane recorded

The lane rated C-intake-23 `no` with "zero hits for an intake
acknowledgement". Read against `development`, that is too strong in one
direction and not strong enough in the other.

Three of the four pieces exist:

- The text. `lib/Settings/templates/ontvangstbevestiging.json` carries a
  Dutch body naming the case, its kenmerk and the decision deadline.
- The renderer. `TermijnNotificationService::renderTemplate()` handles
  `ontvangstbevestiging` as one of its four AWB templates
  (`lib/Service/TermijnNotificationService.php:45,187`).
- The requirement. `openspec/specs/burger-notifications/spec.md`
  REQ-TERM-008 already says what the message contains.

The fourth is missing, and it is the only one that matters: **nothing
sends it.** The single caller of `sendTermijnNotification()` is
`DeadlineNotificationDispatchJob`, a queued job, and nothing enqueues an
`ontvangstbevestiging` when a case is created.
`DeadlineCaseCreatedListener` listens for OpenRegister's
`ObjectCreatedEvent` on the `case` schema and does exactly one thing:
binds a statutory term.

REQ-TERM-008's scenario reads "WHEN the ontvangstbevestiging is sent". It
never says who sends it or when. A requirement with no trigger reads green
and performs nothing, which is why a statutory duty could sit unshipped
behind a spec that describes it.

So the correction runs both ways: dossiq has more than `no` and less than
a working acknowledgement. The row stays a gap.

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-intake-23 | must, matrix hole | no | an automatic acknowledgement goes to the sender the moment the case is created |
| C-communication-54 | must | partial | the applicant is mailed automatically when the case reaches a configured moment |
| C-communication-62 | must | partial | the party is told a message waits on the platform, without the content leaving |
| C-communication-55 | should | no | the citizen chooses how they are told about their case |
| C-communication-32 | should | no | an administrator posts a notice every portal visitor sees, with a display period |

The proving passer, verbatim from the lane
(`_round4/discovery/candidates.json`, C-intake-23, `intake.tsv:19`):
"request-tracker: lib/RT/Action/Autoreply.pm, scrip On Create Autoreply To
Requestors". Two driven passers on that candidate, Freescout and Request
Tracker, and the lane's note: "Five passers. This is Awb 4:3a, the
ontvangstbevestiging, and it is a statutory duty rather than a
convenience. matrix hole".

The shape Request Tracker proves is worth naming: an acknowledgement is a
scrip on create, declared once, not a step somebody adds to each workflow.
Freescout passes it with no phases at all, which is the lane's own note on
why ledger row 3.9, actions on a phase transition, does not cover this.

Two of the three `must` candidates carry documented passers only or one
driven passer. **D6 was answered relevance-led**, so every `must` enters
the corpus whatever its passer count, and a statutory duty is an
obligation rather than a comparison in any case. **D17 was answered for a
broad market**, and none of the twenty `not` candidates is in this
cluster.

## The decisions this rests on

- **D12**, answered for Nextcloud Mail, supplies the transport. The
  acknowledgement goes out over the account Nextcloud Mail holds, through
  dossiq's own wave 1 change `inbound-mail-filters` and the gateway it
  builds. There is no second mail client for this.
- **D16**, one definition with flags per field, decides what the
  acknowledgement may quote back to the citizen: only what the case type
  says the citizen may see.

Cluster 32 itself names no decision. `build-plan.md` places it in wave 2
and Ruben pulled it into wave 1, because it is the statutory one.

## What changes

- A case created from an electronic submission triggers an
  acknowledgement, declared on the case type, not written into a workflow.
- The message names the kenmerk, what was received, the statutory term and
  its end date, how to follow the case, and who to contact. It is sent in
  Dutch, and in the citizen's language where the case type carries one.
- It is sent through the channel the citizen chose, where they chose one,
  and otherwise through the case type's default.
- Where the case type says the content stays on the platform, the mail
  says a message is waiting and carries no case content.
- The acknowledgement is recorded on the case as an outbound
  communication, with its moment, its channel and its recipient, so
  "did we confirm receipt" is answerable in one place.
- A failure to send is a visible failure on the case, not a log line, and
  it is retried.
- The other configured moments ride the same declaration, so "we need
  something from you" is a different message from "your case moved".

## Ownership

dossiq declares the moments and owns the record on the case. The notifier
and the routing are openregister's notification dialect, ADR-031, which
dossiq already consumes. The transport is Nextcloud Mail's, through the
gateway `inbound-mail-filters` builds. The portal notice half of
C-communication-32 is portaliq's surface and dossiq publishes to it.

## Capabilities

- Modified: `burger-notifications`: the acknowledgement now has a trigger,
  a deadline, a record and a failure mode.

## Impact

`lib/Listener/` (a listener on the same `ObjectCreatedEvent`
`DeadlineCaseCreatedListener` already watches),
`lib/Service/TermijnNotificationService.php`,
`lib/Settings/templates/ontvangstbevestiging.json`, the `caseType`
schema (the declared moments and the channel default), the outbound
communication record on the case, Dutch and English strings.

## Out of scope

- The outbound communication log per recipient and per step. Cluster 23,
  integriq, and this change writes into what it will later render.
- The citizen's portal identity. Cluster 7, portaliq, D8.
- The administered portal notice itself. portaliq's surface; dossiq
  publishes and does not host.
