---
kind: code
depends_on: []
---

# Proposal: portal-contact-channel-follows-the-resident

Owner-moves pass of 2026-09-28. The dossiq half of portaliq's open change
`identity-profile-page`, handed to dossiq after dossiq's own lane had
finished. No matrix row of its own.

portaliq `identity-profile-page`, sibling halves, verbatim: "A case app that
sends letters or phones (dossiq) listens for
`PortalContactDetailsChangedEvent` to honour the channel choice. That
listener is the case app's to write." Its design D3: setting
`portalAccount.contactChannel` (`portal`, `email`, `phone`, `post`) raises
`OCA\Portaliq\Event\PortalContactDetailsChangedEvent` carrying the subject
reference, the organisation, the channel, and whether a preferred e-mail
address and phone number exist. "Portaliq sends no letter and makes no call.
The event is the contract."

## Why

A resident tells the portal they want letters by post, or that they read
everything in the portal. dossiq keeps sending the acknowledgement and the
status letters of their running cases by the case type's default channel,
because nothing tells dossiq the resident chose. dossiq already reads a
per-case choice: `CaseTypeAcknowledgement::channelFor()` takes
`case.communicationChannel` when it is set and the case type's default
otherwise. Nothing sets it from the portal.

Decision `build`: a half that a merged portaliq change depends on.

## What changes

- When a resident changes their contact channel in the portal, their running
  dossiq cases take that channel for what dossiq sends them next.
- A choice dossiq cannot send by (phone) is put on the case timeline for the
  handler, and the sending channel is left as it was.
- `case.communicationChannel` is declared as the channel it holds, a slug
  from dossiq's own channel list, instead of a URI nobody writes.

## Capabilities

- Modified: `portal-contribution`.

## Out of scope

- Storing the resident's preference in dossiq. It is portaliq's account data;
  a case filed later takes the channel its intake sets.
- Sending by phone.
