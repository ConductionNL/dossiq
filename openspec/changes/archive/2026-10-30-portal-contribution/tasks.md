# Tasks: portal-contribution

- [x] **T1**: Add `OCA\Dossiq\Portal\PortalContributionProvider` — a
  dependency-free class (`getAudience` + `getContribution`) declaring the supplier
  audience's collections (supplierTender/Contract/Invoice) + inbox
  (supplierMessage), scoped by `supplierRef`. Discovered by Portaliq via
  convention FQCN, duck-typed. LIVE-VERIFIED: a supplier logging into the Portaliq
  portal sees dossiq's tenders/contracts/invoices scoped to their `supplierRef`.

- [x] **T2** (done before 2026-10-09: `src/views/leverancier/` and `manifest.d/60-leverancier.json` are gone, see the MOVED note in `src/registry.js`; the API and facades stay): Retire dossiq's in-app supplier views (`src/views/leverancier/*`,
  `manifest.d/60-leverancier.json`) once Portaliq renders the contribution; keep
  the API + facades.

- [x] **T3** (built 2026-10-10, lane L2: `lib/Settings/register.d/77-supplier-notices.json` declares
  `newSupplierMessage` on `supplierMessage` (created, outbound only), `contractExpiring` on
  `supplierContract` (daily scan, end date within 90 days), `invoiceDue` on `caseSupplierInvoice`
  (daily scan, due date within 7 days, not yet paid, disputed or rejected) and `tenderPublished` on
  `supplierTender` (award date set). Each mails `{kind: email, field: supplierRef.contactEmail}`,
  OpenRegister's new recipient kind (openregister build/dep-dossiq-email-recipient, REQ-ERO-007).
  Test: `tests/Unit/Settings/SupplierNoticesDeclaredTest.php`. Also checked against
  OpenRegister's real NotificationAnnotationValidator from that branch: all four schemas return no
  errors, and a broken control copy is refused. Archive this change only after the openregister PR
  has landed: on an OpenRegister without the kind, these schemas fail import.) Wire `x-openregister-notifications` on `supplierMessage` (and the
  contractExpiring / invoiceDue / tenderPublished rules) to email the supplier via
  OpenRegister's notification engine (email channel + `email` recipient kind through `supplierRef`).
