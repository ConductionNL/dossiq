# Tasks: portal-case-documents

- [x] 1.1 `documents` on `mijnZaken` and `caseDocuments()` on the provider (dossiq#3205).
  - unit: `PortalCaseDocumentsTest::testTheCaseCollectionDeclaresTheDocumentsMethod`
- [x] 1.2 `PortalCaseDocuments` with the rule of D1, run as the system (D2), file on the case (D3).
  - unit: `PortalCaseDocumentsTest::testTheResidentSeesTheDecisionAndWhatWasSentToThem`, `::testEveryEntryIsWellFormedForPortaliq`, `::testNothingIsAnsweredWithoutACaseOrOpenRegister`
- [ ] 2.1 Live check: a DigiD dev session opens a seeded case with a final outgoing letter and a decision in portaliq; both are listed, decision first, and both download. (live pass, decision 139; archived 10 Oct under decision 139, recipe in dossiq STATE.md "Still owed")
