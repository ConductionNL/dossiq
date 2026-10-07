# Tasks: document-correspondents

Tier: V1. Kind: config plus the writers and the surfaces. Row 5.12.
Consumes the party model (openregister#3761) and the generic link roles
`afzender` and `geadresseerde` (dossiq#2849).

- [x] 1.1 `lib/Settings/register.d/71-document-correspondents.json`:
  `informatieobject.sender` and `informatieobject.recipients`, and
  `dispatch` gains `case`, a `relationshipType` enum of the two roles and
  a deprecation note on `contactPersonName`.
  - `@spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md`
- [x] 1.2 `lib/Service/Zaakdossier/DocumentCorrespondents.php`: the rules.
  Resolve a submitted value against the parties of the case by uuid, by
  contact uid and by e-mail address; drop what matches nothing; drop what
  contradicts the direction.
  - unit: a typed name is dropped, a uuid is kept, an address resolves
    case-insensitively, a sender on an outgoing document is dropped
- [x] 1.3 `lib/Service/Zaakdossier/CorrespondentWriter.php`: writes the two
  fields and one `dispatch` row per correspondent, in one call.
  - unit over a doubled store: two recipients give two rows with the role,
    the case and a `sendDate`
- [x] 2.1 `ZaakdossierService::uploadDocument()` and `updateMetadata()`
  take `sender` and `recipients` and pass them through the writer.
- [x] 2.2 Both actions that file an outgoing document set its recipients,
  through the shared `AddressesTheCase` trait: `MergeTemplateHandler` and
  `CreateDocumentHandler`, from the case's addressee, else its requester,
  else its initiator.
- [x] 2.3 `EmailArchivalService`, which is what `InboundMailIntake` calls
  to file a message, sets the sender from the From address. Verified as
  the only caller, so the inbound path has one writer and not two.
- [x] 2.4 `ZaakdossierController::updateMetadata()` forwards the two
  request parameters.
- [x] 3.1 `src/services/documentCorrespondents.js`: the party options, the
  names for stored ids and the filter predicate.
  - vitest, mutation-checked
- [x] 3.2 `DocumentMetadataDialog.vue`: a Sender picker and a Recipients
  picker, offering the parties of the case.
- [x] 3.3 `CasePartiesWidget.vue`: a party's documents, sent and received.
- [x] 3.4 `src/manifest.json` `#CaseDetail` Files tab: declare the Sender
  and Recipients columns, read by nextcloud-vue `files-browser-columns`
  when it lands.
- [x] 3.5 The dossier listing carries both resolved to names and takes a
  `correspondent` filter.
- [x] 4.1 `tests/e2e/document-correspondents.spec.ts`, tagged and not run
  locally.
- [x] 4.2 `lib/Settings/register.d/67-major-case-body.json`: the Dutch
  body `case.caseDeclaredMajor` shipped without (#2859), reported by the
  routing lane's sweep and left for whoever owns a register fragment next.
  - unit: the merged rule carries a Dutch body
