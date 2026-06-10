# Tasks — Member 09: Contract Renewal Backend (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

Traces to giant task 3.4; spec REQ-005.

- [ ] Implement `ContractRenewalService.scanExpiringContracts()` — find endDate within 90 days, set renewalWarning
- [ ] Implement `ContractRenewalService.flagContractWithinThreshold(contractRef)` — compute daysUntilExpiry
- [ ] Implement `ContractRenewalService.requestRenewal(contractRef)` — create Procest zaak Leverancier-contractverlenging-verzoek
- [ ] Implement `ScanExpiringContractsJob` — nightly 03:00 UTC, email suppliers with expiring contracts
- [ ] Create `ContractController`: GET /contracts, GET /contracts/{id}, POST /contracts/{id}/request-renewal
- [ ] Apply member 04 scope validation; restrict renewal to contracts/admin roles
- [ ] Email account manager on renewal request; write request to contract timeline + audit
- [ ] Test contracts at 90-day boundary, < 90, > 90 days
- [ ] Test renewal-request creation and Procest integration
- [ ] Test email notifications to account managers
- [ ] Verify 403 on cross-supplier contract access
