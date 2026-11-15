# Proposal: portal-message-box-recipient

## Why

Portaliq can send a letter from the resident's portal inbox to their
government message box too (portaliq change `inbox-berichtenbox-channel`,
ConductionNL/portaliq#913, spec `openspec/specs/portal-message-box-channel/spec.md`).
Portaliq holds no citizen service number, on purpose, so the case app that
wrote the message names the recipient. Until dossiq does, nothing goes to the
message box for a dossiq message (dossiq#3192).

## What changes

- The `berichten` inbox declares `messageBox: {recipientProvider: "messageBoxRecipient"}`.
- The portal provider gains `messageBoxRecipient(string $messageId): ?string`,
  answered by a new `PortalMessageBoxRecipient` service.

## Out of scope

- Integriq's live digital post binding (`berichtenbox-digital-post-adapter`):
  until it lands every send is simulated.
