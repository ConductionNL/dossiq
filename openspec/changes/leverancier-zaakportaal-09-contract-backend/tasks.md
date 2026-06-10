# Tasks — Member 09: Contract Renewal Backend (code)

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
