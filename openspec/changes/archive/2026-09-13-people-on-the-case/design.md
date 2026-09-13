# Design: people on the case

## D-1 The link is the fact, the role record is the projection

The same shape as `documents-live-on-the-case`: the native thing (an OpenRegister person link) is what a handler creates, and the dossiq record (`role`) is derived from it. So the resolver, the mandate matrix, the routing rules and the Parties widget keep reading `role` and nothing else in the app has to learn about links.

The projection is keyed by `(case, participant, roleType)`, the same triple OpenRegister's link is unique on. A role record that no link produced is left alone: seed data and hand-made roles predate this change and must not disappear when a listener runs. Only a record this projection wrote is updated or removed by it, which it knows by the participant matching a link's uid.

## D-2 The vocabulary keys are role type uuids

`configuration.linkRoles` on the case schema is written from the published role types, `key` being the role type's uuid and `label` its name. A uuid is not pretty, but it is what the projection needs to resolve `roleType` without guessing, it survives a rename, and the picker shows the label rather than the key. The union over case types is deliberate: one case schema serves every case type, and a picker that offered only the current case type's roles would need the case type in the link call, which the generic API does not take.

A role that is not a known uuid still links (OpenRegister refuses it only when the schema declares a vocabulary, which it now does, so a free-text role is refused at the source). The projection skips a link whose role names no role type, and says so in the log rather than writing a role record with no type.

## D-3 The initiator fills the display name, not the reference

`case.requester` is a uuid into another register, with `initiatorType` saying which. A Nextcloud user is not a row there, and neither is a vCard contact. Writing one would put a uid where a uuid is expected and break the Requester column and the handoff.

So an initiator link fills `initiatorDisplayName` (what the list column and the card read) and `initiatorSourceId` (the person's uid, so the projection can find its own writes), and leaves `requester` and `initiatorType` untouched. A case that had a requester keeps it; a case whose initiator is a linked person now shows a name where it showed nothing.

## D-4 The file request is an email share with create-only permission

Nextcloud already has the mechanism: a share of type email on a folder with `CREATE` permission and nothing else is a file request, and the sharebymail backend sends the recipient a link they can upload through. We do not build a second one. The dialog picks a person on the case rather than typing an address, because the address is the point of the whole change: the recipient is a party of this case.

The share is made on the case folder, so an upload lands where `document-projection` already watches, and becomes a document of the case with the defaults of a dropped file. The request carries the note as the share's note and an expiry, both optional.

A person with no email address is listed and disabled with the reason, never hidden: "nobody to send to" is exactly the failure this change exists to end, and hiding the row would reproduce it.
