# People on the case

## Why

Ruben, 2026-09-13, from a file request that could not name a recipient: "we should be able to link users and contacts to objects generically and be able to describe their link, like in what role they are coupled. This also solves the original indiener problem."

Today a person on a dossiq case is a `role` record whose `participant` is a plain string, written by hand or by seed data. Nothing picks a real user or contact, so the People tab lists identifiers, the file request has nobody to send to, and the requester is a separate field with three denormalised projection fields and a back-fill widget behind it.

OpenRegister now keeps people on objects: one link per person per role, a user or a contact, with a validity window and a note, and a vocabulary the schema declares (`people-on-objects`, openregister#3690). @conduction/nextcloud-vue picks them and renders them (#1153). This change makes dossiq use that: the link is the fact, the `role` record is its projection, and a party on the case is somebody you can actually reach.

## What changes

**A person linked to a case becomes a role record.** `PersonLinkListener` hears OpenRegister's three person-link events, ignores every object that is not a case, and keeps one `role` record per link: `roleType` from the link's role (the vocabulary keys are role type uuids), `participant` from the person's uid (`user:<uid>` or the vCard uid), `name` from the display name, `description` from the note. Unlinking removes the record. The existing Parties widget, the role resolver and the mandate matrix read `role` as they always did.

**The case type's role types are the case schema's vocabulary.** `SyncCaseRoleVocabulary` writes `configuration.linkRoles` onto the case schema: one entry per published role type, keyed by its uuid and labelled by its name, so the picker offers the role types this instance actually has. It runs as a repair step and after a role type is saved.

**An initiator link names the requester.** A link whose role type is the generic `initiator` fills `initiatorDisplayName` and `initiatorSourceId` on the case, and clears them when it goes. The `requester` reference and `initiatorType` stay where they are: a Nextcloud user or a vCard contact is not a row in the requester register, and pretending otherwise would break the column that reads it. The case list's Requester column reads `initiatorDisplayName`, so an initiator link is enough to fill it.

**The People tab lists people, not identifiers.** The Parties section renders the contacts integration, so the tab shows the people on the case grouped by role type, with avatars, email, the validity window and the note, and offers "link person" against users and contacts.

**A file request goes to a party of the case.** The Files tab's New menu gains "Request a file from a party" (`newActions`). The dialog lists the people on the case, greys out anyone without an email address and says why, and creates an email share of the case folder with create-only permission, so what the party uploads lands in the case folder and becomes a document of the case through the projection that is already there.

## What does not change

- The `role` and `roleType` schemas, the resolver, the mandate matrix and the routing rules.
- `requester`, `initiatorType` and the initiator picker; they keep working, and an initiator link now fills the display name beside them.
- The ZGW APIs.

## Impact

- `lib/Listener/PersonLinkListener.php`, `lib/Service/People/{CaseRoleProjection,PersonLinkReader,CaseRoleVocabulary}.php` (new), `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`.
- `lib/Repair/SyncCaseRoleVocabulary.php` (new), `appinfo/info.xml`.
- `lib/Controller/FileRequestController.php` (new), `appinfo/routes.php`.
- `src/modals/FileRequestDialog.vue` (new), `src/registry.js`, `src/manifest.json`, `l10n/*`.
- Tests under `tests/Unit`, `tests/vitest` and `tests/e2e`.
- Needs `@conduction/nextcloud-vue` with `newActions` and the widened contacts tab, and OpenRegister with the person link model. Both are open PRs; the manifest entry and the tab section are inert until they land, exactly as the documents change's row actions were.
