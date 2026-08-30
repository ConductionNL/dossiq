# supplier-portal Specification — Member 09: Contract Renewal Backend

---
status: proposed
---

## Purpose

Serve contract list/detail, flag contracts within 90 days of expiry, and process renewal requests
into Procest. Consumes the `SupplierContract` schema and renewal case type from member 01.

## ADDED Requirements

### Requirement: Contract Expiry Detection

The system SHALL flag contracts within 90 days of expiry and compute the days remaining.

#### Scenario: Contract within the threshold is flagged

@e2e exclude Backend-only — driven by the nightly ScanExpiringContractsJob (TimedJob) with no UI surface; covered by ScanExpiringContractsJobTest + ContractRenewalServiceTest. Contract UI is chain member 10.

- GIVEN the nightly expiry-scan job runs
- WHEN a contract's `endDate` is within 90 days
- THEN its `renewalWarning` SHALL be set true and `daysUntilExpiry` computed
- AND the supplier SHALL be emailed about the expiring contract
- AND a contract more than 90 days from expiry SHALL NOT be flagged

### Requirement: Contract Renewal Request

The system SHALL create a Procest renewal zaak when a supplier requests renewal and notify the
account manager.

#### Scenario: Renewal request opens a Procest case

@e2e exclude Backend REST contract — exercised via the Newman leverancier-contract-api collection + ContractControllerTest (role gate, manual-only, window, cross-supplier 403); no UI surface in this chain member. Contract renewal UI is chain member 10.

- GIVEN a contracts or admin user requests renewal of a manual-renewal contract within 90 days
- WHEN the request endpoint is called
- THEN a Procest zaak of type `Leverancier-contractverlenging-verzoek` SHALL be created with the
  contract reference and an email sent to the account manager
- AND a cross-supplier contract request SHALL return 403
