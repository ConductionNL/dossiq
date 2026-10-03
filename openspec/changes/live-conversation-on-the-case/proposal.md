---
kind: code
depends_on: []
---

# Proposal: live-conversation-on-the-case

Round 4 discovery, cluster 70 "Talking to the citizen live, and to the
assistant" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Four candidates, no `must`,
four passers, two driven and two documented, proving system Huly. Owner
dossiq, size M, decision D13. The register's reason for the cluster
carrying nothing: "no change opened".

## Why

A hoorzitting in a bezwaar is held by video now. The lane's clause on
C-communication-31 says where it belongs: "it belongs on the case rather
than in a link pasted into an e-mail".

A link in an e-mail means the conversation has no record on the case, the
attendance is somebody's memory, and a bezwaarcommissie cannot later show
that the belanghebbende was heard.

## What is actually there

Read against `development` at `172d364f`, and the lane's `no` on
C-communication-31 needs correcting.

- `lib/Service/Bezwaar/HearingService.php` and
  `lib/Service/HearingService.php` already create a Nextcloud Talk room
  through `OCP\Talk\IBroker` for a video hearing
  (`lib/Service/HearingService.php:89`), schedule it, record attendance
  and add minutes. `HearingCalendarService` puts it on a calendar.
- So dossiq holds a live conversation inside the product today, for
  exactly one moment of exactly one case type: the hoorzitting in a
  bezwaar. Nothing else on any case can start one.
- Nothing records a call or a voice note against a case. Uploading a file
  Nextcloud already holds is the whole of it, as the lane found.
- Nothing declares a case major, and nothing pulls named responders into a
  channel.
- `openspec/specs/case-assistant-via-hermiq/spec.md` and
  `hermiq-ai-tooling` already exist, which is D13's answer working: the
  assistant is hermiq's and dossiq declares the tools.

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-communication-31 | should | partial | a video call is held inside the product rather than on an external service |
| C-case-core-6 | should | no | a case declared major opens a working channel and pulls named responders into it |
| C-communication-25 | could | partial | a screen recording or a voice note is captured and attached to a record |
| C-communication-67 | could | no | the user dictates into the assistant by voice |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-communication-31, `communication.tsv:29`: "huly: left rail, Office,
  plugins/love (LiveKit empty)". The lane's note: "Both are calling hosted
  by the product itself. lanes disagree, rated should, not".
- C-case-core-6, `case-core.tsv:15`: "jira-service-management: Create
  incidents from alerts, Mark an incident as major, Add and manage
  incident responders, Create chat channel and video conference for an
  incident, About incident conference calls, Create incident timeline in
  Slack, Automatically add responder teams to incident chat channels". Its
  clause: "a calamiteit or a crisis a gemeente has to stand up in fifteen
  minutes".
- C-communication-25, `communication.tsv:69`: "huly: plugins/recorder,
  plugins/media, plugins/image-cropper". Its clause: "a toezichthouder's
  constatering ter plaatse is a photo and a voice note".
- C-communication-67, `communication.tsv:53`: "opencase: AI side panel
  (code-census.md)". Its clause: "a balie medewerker with a queue behind
  them types badly".

**D6 was answered relevance-led.** This cluster has no `must`, so every
member enters on relevance. Three do, and the fourth is hermiq's and is
named rather than built here. **D17** does not reach this cluster.

**D13 was answered option 1**: hermiq owns the assistant and dossiq
declares the tools and the rights. The decisions file names this cluster
as one of the two that wait on it: "Waits on it: AI and what it is allowed
to read, Talking to the citizen live".

## What changes

- A live conversation is started from any case, not only from a bezwaar
  hoorzitting. It runs in Nextcloud Talk, and the case records that it
  happened, when, who joined and for how long.
- What a conversation produces, a recording, a transcript, minutes, is a
  document on the case under the case type's own visibility rules, not a
  file in somebody's folder.
- A voice note or a screen recording is captured and attached to a case or
  a task, with the same rules.
- A case is declared major. Declaring it opens one working channel, pulls
  in the responders the case type names, and records who was pulled in and
  when.
- A major case's channel is closed with the case, and what was said in it
  is kept with the case.
- Dictating to the assistant is hermiq's, and dossiq's declared tools are
  reachable by voice because they are reachable at all.

## Ownership

dossiq owns the declaration, the record on the case and the rules about
what may be seen. It owns no transport, no recorder and no model.

| half | app | artefact |
|---|---|---|
| the room, the call and the recording | nextcloud | Talk, through `OCP\Talk\IBroker`, already used by `HearingService` |
| the file the recording becomes | nextcloud | Files, placed as a case document by dossiq `documents-live-on-the-case` |
| what the citizen may see of it | dossiq and portaliq | the case type's visibility flags, D16, and the portal contribution contract, shipped |
| the assistant, and dictating to it | hermiq | dossiq `case-assistant-via-hermiq`, a spec, and `hermiq-ai-tooling`, open. D13 settles it: hermiq owns the assistant |
| the record of what the model read | openregister | the audit trail, per D13 |
| redaction before a model or a citizen reads a transcript | filinq | the redaction client, per D13 |

Every half has an artefact. This change opens no request for a new change
in another repo.

## ADRs

- Company ADR-011: search OpenRegister before implementing a utility.
  dossiq ships no media recorder, no transcoder and no signalling.
- Company ADR-031: the canonical notification dialect. Pulling a responder
  into a major case is a notification, not an imperative dispatch.
- Company ADR-102: config absence fails closed with a status. A case type
  declaring responders that cannot be resolved refuses the major
  declaration rather than opening a channel nobody is in.

## Capabilities

- Modified: `case-management`: a live conversation runs from any case, is
  recorded on it, and a major case opens a channel with named responders.
- Modified: `case-assistant-via-hermiq`: dictation is hermiq's, and
  dossiq's declared tools carry no separate voice path.

## Impact

`lib/Service/HearingService.php` and `lib/Service/Bezwaar/HearingService.php`
(generalised), `lib/Service/HearingCalendarService.php`, the `case` and
`caseType` schemas, the case page's communication surface, Dutch and
English strings.

## Where this change disagrees with the register

- **C-communication-31 is rated `partial` by one lane note and `no` by
  another, and `partial` is right for a reason neither gives.** dossiq
  already creates a Talk room through `IBroker`, schedules it, records
  attendance and files minutes. It is bound to the bezwaar hoorzitting, so
  the work here is generalising a working mechanism to any case, not
  building calling.

## Out of scope

- The assistant itself, and dictation. hermiq, per D13.
- The outbound communication log per recipient and per step. Cluster 23,
  integriq.
- The contact moment record. pipelinq, register row 6.2.
- Anonymising a document before a model reads it. filinq, per D13.
