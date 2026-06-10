# Tasks — Member 12: Master Data Self-Service (code)

Traces to giant tasks 3.5 and 2.6; spec REQ-007.

- [~] Implement `SupplierMasterDataMutationService.updateAddress(supplierRef, newAddress)` — apply immediately, log audit — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierMasterDataMutationService.updateContactPerson(supplierRef, newContact)` — apply immediately, log audit — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierMasterDataMutationService.requestIBANChange(supplierRef, newIBAN)` — create 4-eyes Procest zaak, do NOT apply — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierMasterDataMutationService.submitForVerification(supplierRef, dataType, attachments)` — create verification zaak — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `ProcessMasterDataMutationsJob` — finalise approved IBAN/accreditation mutations — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `ProfileController`: GET /profile, POST /profile/{address,contact,iban,accreditations} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Enforce financial re-auth on IBAN change; audit-log all mutations with masked IBAN — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build `ProfileForm` / ProfileView: address, contact, IBAN, SBI, accreditations sections — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build `IBANVerificationFlow`: step 1 verify current, step 2 new IBAN format validation, step 3 confirm — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On IBAN submit show "Wijziging ingediend"; on address show immediate confirmation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build accreditation form: known types + proof upload + "Indienen voor verificatie" — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test address change (immediate application) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test IBAN change (4-eyes Procest workflow, not applied until approved) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test accreditation submission (not auto-applied) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify email notifications and audit logging on each update — deferred to downstream cycle / fleet-wide adoption (handoff)
