# Tasks — Member 01: Schema Foundation (config)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

Traces to giant tasks 6.1, 4.3, 6.2. Declare-first config member.

- [ ] Declare `Supplier` schema (kvkNumber, legalName, status, address, contactPerson, iban, sbiCodes, accreditations)
- [ ] Declare `SupplierUser` schema with `supplierRef` reference (role, status, eherkenningLevel, activationToken)
- [ ] Declare `SupplierTender` schema with `supplierRef` (status, submittedDate, value, awardDate, rejectionReason, appealDeadline)
- [ ] Declare `SupplierContract` schema with `supplierRef` (number, startDate, endDate, value, accountManager, renewalOption, renewalWarning)
- [ ] Declare `SupplierInvoice` schema with `supplierRef` (number, invoiceDate, amount, vatAmount, status, dueDate, expectedPaymentDate, actualPaymentDate)
- [ ] Declare `SupplierMessage` schema (direction, body, attachmentRefs, sentBy, sentAt), marked write-once
- [ ] Declare `SupplierKPI` schema (period, avgPaymentDays, onTimePercentage, disputeRate, complianceScore, benchmark, sufficientData)
- [ ] Declare indexes on `supplierRef`, `status`, and date fields across the Supplier* schemas
- [ ] Declare Procest case type `Leverancier-contractverlenging-verzoek`
- [ ] Declare Procest case type `Leverancier-IBAN-wijziging` with 4-eyes workflow posture
- [ ] Declare Procest case types `Leverancier-accreditatie-verificatie` and `Leverancier-mutatie`
- [ ] Register the seven schemas + four case types via a `lib/Repair/` step wired in info.xml
- [ ] Implement idempotent seed repair step: 3 suppliers, 5 users, 5 tenders, 4 contracts, 5 invoices, 1 message thread
- [ ] Add integration test asserting materialised records exist with correct `supplierRef` cross-references
- [ ] Add integration test asserting the seed repair step is idempotent (counts unchanged on re-run)
