## MODIFIED Requirements

### Requirement: Daily billing export to Shillinq (REQ-007-B)

The system SHALL hand each tenant-month of pending billing events to shillinq by raising
shillinq's `BillablePeriodClosedEvent` in-process (sourceApp `dossiq`, externalReference the
tenant id, period `YYYY-MM`, one priced line per event). Shillinq drafts the invoice for the
customer whose external reference is the tenant id (decision 174). A refusal, an absent shillinq
or an unanswered event SHALL leave the events unbilled and SHALL be recorded on the tenant's
audit trail with its reason.

#### Scenario: Export sets invoiceRef on success

- **GIVEN** events with invoiceRef=NULL exist for a tenant whose id a shillinq customer carries
- **WHEN** the tenant's month is invoiced
- **THEN** shillinq SHALL answer with the drafted invoice
- **AND** the events for that tenant-month SHALL be updated with the returned invoice id
- **AND** invoicing the same month again SHALL answer with the same invoice (only invoiceRef-NULL rows are touched, no double-invoicing)

@e2e exclude A same-process event between dossiq and shillinq has no browser surface; covered by tests/Unit/Service/ShillinqIntegrationServiceTest.php and shillinq's BillablePeriodInvoiceServiceTest, live pass in dossiq-delivers-nothing phase 5.

#### Scenario: A refusal is recorded and nothing is stamped

- **GIVEN** no shillinq customer carries the tenant id
- **WHEN** the tenant's month is invoiced
- **THEN** no event SHALL be stamped
- **AND** the tenant's audit trail SHALL record `invoice.refused` with shillinq's reason

@e2e exclude A same-process event between dossiq and shillinq has no browser surface; covered by tests/Unit/Service/ShillinqIntegrationServiceTest.php, live pass in dossiq-delivers-nothing phase 5.

#### Scenario: Export retries then defers on failure

- **GIVEN** shillinq is not installed, or no shillinq listener answers
- **WHEN** the tenant's month is invoiced
- **THEN** no event SHALL be stamped and the refusal SHALL be recorded on the tenant's audit trail
- **AND** the next run SHALL pick the same unbilled events up again; there is no in-request retry loop, because an in-process event either answers or does not

@e2e exclude A same-process event between dossiq and shillinq has no browser surface; covered by tests/Unit/Service/ShillinqIntegrationServiceTest.php (testNoListenerAnsweringIsARefusalToo).
