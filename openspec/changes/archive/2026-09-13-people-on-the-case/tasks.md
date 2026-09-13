# Tasks: people on the case

## 1. The projection

- [x] 1.1 `PersonLinkReader`: the people on a case from OpenRegister's contacts endpoint (results, byRole, roles), and one link by uid.
- [x] 1.2 `CaseRoleProjection`: upsert and delete the `role` record of a link; resolve `roleType` from the link's role; fill and clear `initiatorDisplayName` / `initiatorSourceId` for the generic initiator.
- [x] 1.3 `PersonLinkListener` on `PersonLinkedEvent`, `PersonLinkUpdatedEvent`, `PersonUnlinkedEvent`; registered in `ObjectListenerRegistrar`; never throws.
- [x] 1.4 Unit tests for all three.

## 2. The vocabulary

- [x] 2.1 `CaseRoleVocabulary`: the published role types as `linkRoles` entries, written onto the case schema through OpenRegister's schema API.
- [x] 2.2 `SyncCaseRoleVocabulary` repair step (post-migration, ADR-106) plus a run after a role type is saved; `appinfo/info.xml` version bump and the install exemption entry.
- [x] 2.3 Unit tests.

## 3. The surfaces

- [x] 3.1 Manifest: the People tab's Parties section becomes the contacts integration; the case-files widget declares the `newActions` entry for the file request.
- [x] 3.2 `FileRequestDialog.vue`: the people on the case, an email-less person disabled with the reason, a note and an expiry, POST to the endpoint; registry entry; l10n.
- [x] 3.3 vitest for the dialog.

## 4. The endpoint

- [x] 4.1 `FileRequestController::create`: resolve the person on the case, refuse one without an email with 422, create the email share of the case folder with create-only permission, answer the recipient and the link.
- [x] 4.2 Route; unit tests.

## 5. Checks

- [x] 5.1 `openspec validate people-on-the-case --strict`.
- [ ] 5.2 Diff check on the touched files; `composer check:strict` once; `npm run lint`; e2e written.
