# Tasks: the-woo-publication-carries-its-documents

- [x] 1.1 A case file is read through OpenRegister's managed tree first (`FindsFilesById`), in `WooCaseDocuments` and `RedactedCopyRecorder`.
  - `tests/Unit/Woo/WooPublicationCarriesItsDocumentsTest.php`: the root folder finds nothing, as for the handler; the public document and the redacted copy are attached, the uncopied partly public and the withheld document are not. Control: the same test against the old reader attaches nothing and still reports the publish as done.
- [x] 1.2 A publish with an unreadable disclosable document is refused with 409 `documents_unreadable`.
  - `WooPublicationCarriesItsDocumentsTest::testAPublicationWhoseFilesCannotBeReadIsRefused`, `WOOAssessmentControllerTest::testAnUnreadableDocumentRefusesThePublish`.
- [x] 1.3 The dossier return reads and writes the requester's collection unscoped, and refuses a collection that is not the requester's.
  - `tests/Unit/Woo/WooDossierReturnTest.php` runs against a store that hides the collection from a scoped read and refuses a scoped write. Control: the old return fails three of its tests on that store.
