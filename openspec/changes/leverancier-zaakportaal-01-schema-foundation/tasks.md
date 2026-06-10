# Tasks — Member 01: Schema Foundation (config)

Traces to giant tasks 6.1, 4.3, 6.2. Declare-first config member.

- [~] Declare `Supplier` schema (kvkNumber, legalName, status, address, contactPerson, iban, sbiCodes, accreditations) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `SupplierUser` schema with `supplierRef` reference (role, status, eherkenningLevel, activationToken) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `SupplierTender` schema with `supplierRef` (status, submittedDate, value, awardDate, rejectionReason, appealDeadline) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `SupplierContract` schema with `supplierRef` (number, startDate, endDate, value, accountManager, renewalOption, renewalWarning) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `SupplierInvoice` schema with `supplierRef` (number, invoiceDate, amount, vatAmount, status, dueDate, expectedPaymentDate, actualPaymentDate) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `SupplierMessage` schema (direction, body, attachmentRefs, sentBy, sentAt), marked write-once — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `SupplierKPI` schema (period, avgPaymentDays, onTimePercentage, disputeRate, complianceScore, benchmark, sufficientData) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare indexes on `supplierRef`, `status`, and date fields across the Supplier* schemas — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare Procest case type `Leverancier-contractverlenging-verzoek` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare Procest case type `Leverancier-IBAN-wijziging` with 4-eyes workflow posture — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare Procest case types `Leverancier-accreditatie-verificatie` and `Leverancier-mutatie` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register the seven schemas + four case types via a `lib/Repair/` step wired in info.xml — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement idempotent seed repair step: 3 suppliers, 5 users, 5 tenders, 4 contracts, 5 invoices, 1 message thread — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add integration test asserting materialised records exist with correct `supplierRef` cross-references — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add integration test asserting the seed repair step is idempotent (counts unchanged on re-run) — deferred to downstream cycle / fleet-wide adoption (handoff)
