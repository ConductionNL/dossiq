# Tasks — Member 12: Master Data Self-Service (code)

Traces to giant tasks 3.5 and 2.6; spec REQ-007.

- [ ] Implement `SupplierMasterDataMutationService.updateAddress(supplierRef, newAddress)` — apply immediately, log audit
- [ ] Implement `SupplierMasterDataMutationService.updateContactPerson(supplierRef, newContact)` — apply immediately, log audit
- [ ] Implement `SupplierMasterDataMutationService.requestIBANChange(supplierRef, newIBAN)` — create 4-eyes Procest zaak, do NOT apply
- [ ] Implement `SupplierMasterDataMutationService.submitForVerification(supplierRef, dataType, attachments)` — create verification zaak
- [ ] Implement `ProcessMasterDataMutationsJob` — finalise approved IBAN/accreditation mutations
- [ ] Create `ProfileController`: GET /profile, POST /profile/{address,contact,iban,accreditations}
- [ ] Enforce financial re-auth on IBAN change; audit-log all mutations with masked IBAN
- [ ] Build `ProfileForm` / ProfileView: address, contact, IBAN, SBI, accreditations sections
- [ ] Build `IBANVerificationFlow`: step 1 verify current, step 2 new IBAN format validation, step 3 confirm
- [ ] On IBAN submit show "Wijziging ingediend"; on address show immediate confirmation
- [ ] Build accreditation form: known types + proof upload + "Indienen voor verificatie"
- [ ] Test address change (immediate application)
- [ ] Test IBAN change (4-eyes Procest workflow, not applied until approved)
- [ ] Test accreditation submission (not auto-applied)
- [ ] Verify email notifications and audit logging on each update
