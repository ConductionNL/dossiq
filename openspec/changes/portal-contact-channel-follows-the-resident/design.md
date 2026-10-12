# Design: portal-contact-channel-follows-the-resident

Read at dossiq `development` `c8ac7427e` and portaliq `development`
`8934e73`.

## Context

- portaliq `identity-profile-page` D3 (open, task T04 unchecked): the event
  `OCA\Portaliq\Event\PortalContactDetailsChangedEvent` with `subjectRef`,
  `organisation`, `channel` (`portal`, `email`, `phone`, `post`) and whether a
  preferred e-mail and phone exist. At portaliq `8934e73` no class of that
  name exists in `lib/`: nothing emits it yet.
- dossiq `lib/Service/CaseTypeAcknowledgement.php:207` `channelFor()`: "The
  citizen's own recorded choice wins where there is one; the case type's
  default applies otherwise [...] `case.communicationChannel` is read as the
  recorded choice". The case type's `defaultChannel` is one of `email`,
  `portal`, `post` (`lib/Settings/register.d/36-ontvangstbevestiging.json`).
- dossiq schema `case` (`lib/Settings/dossiq_register.json`):
  `communicationChannel` is declared `type: string, format: uri`, "URL
  reference to the communication channel", while `channelFor()` reads it as
  a slug. `portalSubject` is the resident's portal subject reference.
- dossiq `lib/Listener/PortalClientWriteListener.php` and
  `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php:209`: the pattern for a
  portaliq event: the FQCN as a string, registration guarded by
  `class_exists`, duck-typed getters, never throws.

## D1. A listener in the existing cross-app pattern

`PortalContactDetailsChangedListener`, registered in
`CrossAppListenerRegistrar` only when the event class exists, reads
`getSubjectRef()`, `getOrganisation()` and `getChannel()` by
`method_exists`, and never throws into portaliq's request.

## D2. Running cases take the channel

The listener finds the cases whose `portalSubject` equals the subject
reference and whose `endDate` is empty, in the organisation named by the
event, and sets `communicationChannel` on each:

| portal channel | dossiq channel |
| --- | --- |
| `portal` | `portal` |
| `email` | `email` |
| `post` | `post` |
| `phone` | unchanged; a timeline entry "The applicant prefers to be phoned." |

Each change is a timeline entry on the case ("Contact channel changed to post
by the applicant in the portal"), so the handler sees why the next letter
goes another way. Ended cases are left alone.

## D3. The property says what it holds

`communicationChannel` is re-declared in `dossiq_register.json` as a nullable
string with the enum `email`, `portal`, `post`, `website`, `zgw-api` (the slugs
`CaseTypeAcknowledgement` knows), `format` removed. Ruben decided on 10 Oct
(decision 171, Q-dossiq-L2-3), against the recommendation, to add the enum and
to map ZGW URLs to slugs at the ZGW boundary, so no ZGW-created case is refused:

- `CommunicationChannel` maps a ZGW `communicatiekanaal` URL to the slug an
  administrator configured for it (`zgw_communication_channel_map`), else
  `zgw-api`, and the words an older intake wrote ("E-mail", "brief") to their
  slug. The value a case arrived as is kept in `communicationChannelSource`.
- `ZgwService` runs it on every zaak write, and a zaak read answers with the
  URL it was created with (or the configured URL of its slug, or "").
- Channel intake normalises a message's channel the same way; a word that
  names no channel writes no channel rather than a value the enum refuses.
- `NormaliseCommunicationChannelValues` converts every stored value once,
  after the register import, and names each converted case in the log.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Hearing portaliq's event and updating running cases | Imperative, a listener | A cross-app event (ADR-041) fanned out over a query. |
| What the property holds | Declarative, the register | A schema correction. |
| Mapping ZGW URLs and intake words onto slugs | Imperative, at each boundary | The ZGW API and channel intake write the property; the mapping has to run before the enum sees the value. |

## Risks

- **Listening for an event nothing emits.** The listener is inert until
  portaliq ships the event; task 3.1 does not close before a `git grep` in
  portaliq finds `new PortalContactDetailsChangedEvent`.
- **A resident with many cases.** One update per running case; the event is
  rare (a settings change), so no batching.
