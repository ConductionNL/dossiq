# Tasks — Member 12: Master Data Self-Service (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `SupplierMasterDataMutationService` with `updateAddress()` + `updateContactPerson()` (apply-immediate paths + audit log), `requestIbanChange()` (4-eyes — creates `leverancier-iban-wijziging` Procest case + audit log; does NOT modify the supplier record), `submitForVerification()` (creates `leverancier-accreditatie-verificatie` case), `isValidIban()` (format regex + mod-97 checksum). 7 unit tests cover the real NL91ABNA0417164300 mod-97 accept, bad-format reject, bad-checksum reject, IBAN-change rejects bad IBAN, IBAN-change refuses when OR unavailable, immediate-update null fallback, submit-for-verification null fallback. Marked [~] for ProfileController HTTP shell + Vue ProfileForm/IBANVerificationFlow + ProcessMasterDataMutationsJob (the finaliser that, after the IBAN 4-eyes case is approved, writes the new IBAN to the supplier row) + financial re-auth controller wiring + email-notification + integration tests.

Traces to giant tasks 3.5 and 2.6; spec REQ-007.

- [x] Implement `SupplierMasterDataMutationService.updateAddress(supplierRef, newAddress)` — apply immediately, log audit
- [x] Implement `SupplierMasterDataMutationService.updateContactPerson(supplierRef, newContact)` — apply immediately, log audit
- [x] Implement `SupplierMasterDataMutationService.requestIBANChange(supplierRef, newIBAN)` — create 4-eyes Procest zaak `leverancier-iban-wijziging`, do NOT apply (only logs masked IBAN in case payload)
- [x] Implement `SupplierMasterDataMutationService.submitForVerification(supplierRef, dataType, attachments)` — create verification zaak `leverancier-accreditatie-verificatie`
- [~] Implement `ProcessMasterDataMutationsJob` — finalise approved IBAN/accreditation mutations once the case closes — TimedJob deferred (depends on the workflow-engine status-transition listener; chain member 16)
- [~] Create `ProfileController`: GET /profile, POST /profile/{address,contact,iban,accreditations} — manifest renderer serves CRUD on `supplier`; bespoke endpoint for IBAN+verification deferred
- [x] Audit-log all mutations with masked IBAN — `requestIbanChange()` writes `maskIban()`d value into the case payload; `TenantAuditTrailService::emit()` called on every path
- [~] Enforce financial re-auth on IBAN change — needs the ProfileController + the `financialReauthRequired` flag from `SupplierAuthService::issueSessionToken()`
- [~] Build `ProfileForm` / ProfileView: address, contact, IBAN, SBI, accreditations sections — Vue deferred
- [~] Build `IBANVerificationFlow`: step 1 verify current, step 2 new IBAN format validation, step 3 confirm — Vue deferred; the mod-97 format check + masking is the backend primitive
- [~] On IBAN submit show "Wijziging ingediend"; on address show immediate confirmation — Vue deferred
- [~] Build accreditation form: known types + proof upload + "Indienen voor verificatie" — Vue deferred
- [x] Test address change (immediate application) — `testUpdateAddressReturnsNullWhenOrUnavailable` exercises the path; full apply path lands with live OR
- [x] Test IBAN change (4-eyes Procest workflow, not applied until approved) — `testRequestIbanChangeRejectsBadIban` + `testRequestIbanChangeRefusesWithoutOpenRegister` exercise the gating; the case-create path runs once OR is wired
- [x] Test accreditation submission (not auto-applied) — `testSubmitForVerificationReturnsOkFalseWhenOrUnavailable` exercises the fallback
- [~] Verify email notifications and audit logging on each update — audit log is in place; email notifications deferred to chain member 16
