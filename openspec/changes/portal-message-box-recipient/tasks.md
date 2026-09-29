# Tasks: portal-message-box-recipient

- [x] 1.1 `messageBox` on `berichten` and `messageBoxRecipient()` on the provider (dossiq#3192).
  - unit: `PortalMessageBoxRecipientTest::testTheInboxDeclaresTheRecipientMethod`
- [x] 1.2 `PortalMessageBoxRecipient` with the rule of D1 and D3.
  - unit: `PortalMessageBoxRecipientTest::testALetterToTheApplicantNamesTheirBsn`, `::testEverythingElseNamesNobody`
- [ ] 2.1 Live check, once integriq's adapter lands: a handler's letter on a seeded DigiD case reaches the simulated message box with the applicant's BSN, and a resident's reply does not.
