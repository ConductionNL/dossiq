# Tasks — Member 09: Contract Renewal Backend (code)

Traces to giant task 3.4; spec REQ-005.

- [~] Implement `ContractRenewalService.scanExpiringContracts()` — find endDate within 90 days, set renewalWarning — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `ContractRenewalService.flagContractWithinThreshold(contractRef)` — compute daysUntilExpiry — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `ContractRenewalService.requestRenewal(contractRef)` — create Procest zaak Leverancier-contractverlenging-verzoek — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `ScanExpiringContractsJob` — nightly 03:00 UTC, email suppliers with expiring contracts — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `ContractController`: GET /contracts, GET /contracts/{id}, POST /contracts/{id}/request-renewal — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Apply member 04 scope validation; restrict renewal to contracts/admin roles — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Email account manager on renewal request; write request to contract timeline + audit — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test contracts at 90-day boundary, < 90, > 90 days — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test renewal-request creation and Procest integration — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test email notifications to account managers — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify 403 on cross-supplier contract access — deferred to downstream cycle / fleet-wide adoption (handoff)
