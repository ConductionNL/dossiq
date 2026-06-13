---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# supplier-portal Specification

## Purpose
TBD - created by archiving change leverancier-zaakportaal-01-schema-foundation. Update Purpose after archive.
## Requirements
### Requirement: Supplier-Portal Schemas Are Registered

The system SHALL register seven OpenRegister schemas — `Supplier`, `SupplierUser`,
`SupplierTender`, `SupplierContract`, `SupplierInvoice`, `SupplierMessage`, and `SupplierKPI` —
through the procest register on app install or upgrade, including the relations between them.

#### Scenario: All seven schemas exist after install

- GIVEN a fresh procest install with OpenRegister available
- WHEN the schema-registration repair step runs
- THEN each of the seven schemas SHALL be retrievable from the procest register with a schema UUID
- AND `SupplierUser` SHALL declare a `supplierRef` reference to `Supplier`
- AND `SupplierTender`, `SupplierContract`, and `SupplierInvoice` SHALL each declare a
  `supplierRef` reference to `Supplier`
- AND each Supplier* schema SHALL declare an index on `supplierRef`, `status`, and its primary
  date field

#### Scenario: SupplierMessage is declared write-once

- GIVEN the `SupplierMessage` schema is registered
- WHEN its schema definition is inspected
- THEN it SHALL declare `direction`, `body`, `attachmentRefs`, `sentBy`, and `sentAt` fields
- AND it SHALL be marked for write-once handling so that immutability can be enforced at the API
  layer by a later chain member

### Requirement: Supplier Procest Case Types Are Declared

The system SHALL declare four Procest zaaktypes used by the portal mutation and renewal flows:
`Leverancier-contractverlenging-verzoek`, `Leverancier-IBAN-wijziging`,
`Leverancier-accreditatie-verificatie`, and `Leverancier-mutatie`.

#### Scenario: All four case types exist after install

- GIVEN a fresh procest install
- WHEN the case-type registration repair step runs
- THEN each of the four supplier zaaktypes SHALL be retrievable by its identifier
- AND `Leverancier-IBAN-wijziging` SHALL declare a 4-eyes approval workflow posture

### Requirement: Reference Seed Data Is Created Idempotently

The system SHALL seed reference data — 3 Supplier records, 5 SupplierUser records (covering the
admin, finance, contracts, sales, and read_only roles), 5 SupplierTender, 4 SupplierContract,
5 SupplierInvoice, and 1 SupplierMessage thread — through an idempotent repair step that creates
no duplicates on re-run.

#### Scenario: Seed data materialises with correct references

- GIVEN the supplier-portal schemas are registered
- WHEN the seed repair step runs for the first time
- THEN 3 Supplier records SHALL exist
- AND 5 SupplierUser records SHALL exist, one per role
- AND 5 SupplierTender records SHALL exist, one per tender status
- AND at least one SupplierContract SHALL fall within the 90-day expiry window
- AND at least one SupplierInvoice SHALL be more than 90 days overdue
- AND every Supplier* record's `supplierRef` SHALL resolve to an existing Supplier

#### Scenario: Re-running the seed step creates no duplicates

- GIVEN the seed repair step has already run once
- WHEN it runs a second time
- THEN the record counts SHALL remain unchanged (3 / 5 / 5 / 4 / 5 / 1)
- AND no duplicate Supplier, SupplierUser, SupplierTender, SupplierContract, SupplierInvoice, or
  SupplierMessage record SHALL be created

### Requirement: eHerkenning Login and KvK Validation

The system SHALL authenticate suppliers through the OpenConnector eHerkenning broker, validate the
KvK claim against the `Supplier` register, and reject login when the supplier is unknown or
inactive.

#### Scenario: Login redirect carries the required scopes

- GIVEN a supplier visits the portal login page
- WHEN they click "Inloggen met eHerkenning"
- THEN the system SHALL redirect to the OpenConnector broker with `response_type=code`,
  `scope=openid profile kvk`, and the callback `redirect_uri`
- AND a temporary state token SHALL be set to prevent CSRF

#### Scenario: Valid KvK claim resolves to an active supplier

- GIVEN eHerkenning returns an authorization code
- WHEN the system exchanges it and extracts the `kvkNumber` claim
- THEN it SHALL look up the `Supplier` by `kvkNumber`
- AND if the Supplier exists with `status` = active it SHALL proceed to session issuance
- AND if the Supplier does not exist OR is inactive/blacklisted it SHALL display the appropriate
  Dutch error and create NO session

### Requirement: SupplierUser Linking and Session Issuance

The system SHALL create or link a `SupplierUser` for the authenticated individual and issue a
session token with a 2-hour TTL and a financial re-authentication flag.

#### Scenario: First login auto-creates a read_only SupplierUser

- GIVEN a valid KvK claim for an active Supplier with no matching SupplierUser
- WHEN the session is established
- THEN a `SupplierUser` SHALL be created with `role` = read_only, `eherkenningLevel` from the
  token, `addedBy` = system, and `status` = active
- AND a session token SHALL be issued with TTL = 2 hours and `requiresReAuthForFinancial` = true

#### Scenario: Session refreshes before expiry and rejects after expiry

- GIVEN an active session within 15 minutes of its 2-hour expiry
- WHEN the user performs an action
- THEN the session token SHALL be refreshed transparently and the refresh logged for audit
- AND GIVEN an expired session WHEN a protected route is accessed THEN the user SHALL be
  redirected to login with a session-expired message

### Requirement: Supplier Admin User Invitation and Activation

The system SHALL let a supplier admin invite a colleague by email and role, and SHALL activate the
invitation only when the invitee authenticates via eHerkenning with a matching KvK claim.

#### Scenario: Admin invites a colleague

- GIVEN a SupplierUser with `role` = admin
- WHEN they submit an invitation with an email and a role
- THEN a `SupplierUser` SHALL be created with `status` = invited and a 64-char activation token
  that expires in 7 days
- AND an invitation email containing the activation link SHALL be sent

#### Scenario: Activation rejects a mismatched organisation

- GIVEN a valid, unexpired activation token
- WHEN the invitee authenticates via eHerkenning
- THEN if the authenticated KvK claim matches the invited Supplier the SupplierUser `status` SHALL
  become active and the token marked used
- AND if the KvK claim does not match, activation SHALL be rejected with an error and no access
  granted

### Requirement: Role Management and Tab Visibility

The system SHALL restrict role changes and access revocation to admins, log every change, and
render dashboard tabs according to the role→tab matrix.

#### Scenario: Admin changes a member role

- GIVEN a SupplierUser with `role` = admin viewing a team member
- WHEN they change the member's role and confirm
- THEN the member's `role` SHALL be updated, an audit entry written, and the member emailed
- AND a non-admin caller SHALL be denied the role-change endpoint

#### Scenario: Revocation invalidates access and tabs follow role

- GIVEN an admin revokes a member's access
- WHEN the member's sessions next refresh
- THEN the member SHALL be logged out and unable to re-access
- AND a finance-role user SHALL see the Invoices tab but NOT the Tenders tab, per the role matrix

### Requirement: Supplier-Scoped Data Access

The system SHALL restrict every supplier-facing data query to the logged-in supplier's own
records and SHALL deny cross-supplier access.

#### Scenario: A supplier sees only its own cases

- GIVEN supplier A is logged in
- WHEN supplier A requests its cases
- THEN only supplier A's cases SHALL be returned
- AND a supplier with no cases SHALL receive an empty result set

#### Scenario: Cross-supplier access is forbidden

- GIVEN supplier B is logged in
- WHEN supplier B requests a case belonging to supplier A
- THEN the system SHALL return 403 Forbidden and disclose no data

### Requirement: API Security Middleware

The system SHALL reject unauthenticated requests, rate-limit by IP, and audit-log mutations with
masked PII.

#### Scenario: Unauthenticated and over-limit requests are rejected

- GIVEN a request with no valid session
- WHEN it reaches a protected endpoint
- THEN the system SHALL return 401 Unauthorized
- AND GIVEN more than 100 requests in one minute from a single IP THEN the system SHALL return 429

#### Scenario: Mutations are audit-logged with masked sensitive fields

- GIVEN a POST/PUT/DELETE on a sensitive resource (e.g. IBAN change)
- WHEN it is processed
- THEN an audit entry SHALL record user, timestamp, action, and old/new values
- AND IBAN SHALL be masked to the last 4 digits and email to the domain only in the log

### Requirement: Tender Status and Detail API

The system SHALL expose a supplier-scoped API returning tender status, dates, values, and
status-specific fields including the legally mandated rejection motivation.

#### Scenario: Awarded tender exposes award detail

- GIVEN a scoped request for an awarded tender
- WHEN the detail endpoint responds
- THEN it SHALL include the status, award date, contract value, and an award-letter download link

#### Scenario: Rejected tender exposes motivation and appeal deadline

- GIVEN a scoped request for a rejected tender
- WHEN the detail endpoint responds
- THEN it SHALL include the rejection reason (per Aanbestedingswet 2012 art. 2.130), an appeal
  deadline of 20 days from the rejection date, and an anonymized evaluation-report download link
- AND a request for a tender outside the supplier's scope SHALL return 403 or 404

### Requirement: Anonymized Document Download

The system SHALL serve the evaluation report only when it is marked anonymized and SHALL log the
download.

#### Scenario: Evaluation report download is gated on anonymization

- GIVEN an awarded or rejected tender with an evaluation report
- WHEN the user requests the report
- THEN the system SHALL verify the PDF is marked anonymized before serving it with
  `Content-Disposition: attachment`
- AND the download event SHALL be written to the audit trail

### Requirement: Expected Payment Date Calculation

The system SHALL calculate an invoice's expected payment date from its date, the Decidesk mandate
routing delay, and the payment-term days, with a safe fallback when Decidesk is unavailable.

#### Scenario: Approved invoice exposes a forecast date

- GIVEN an approved invoice with a 30-day payment term and a 5-day mandate routing delay
- WHEN the detail endpoint responds
- THEN the expected payment date SHALL equal invoice date + 5 + 30 days
- AND WHEN Decidesk is unavailable THEN a default 5-day routing delay SHALL be used rather than
  failing the request

### Requirement: Age Analysis and Overdue Alerting

The system SHALL bucket overdue invoices and email the supplier when an invoice passes 90 days
overdue.

#### Scenario: Age analysis returns correct buckets

- GIVEN a scoped age-analysis request
- WHEN it responds
- THEN it SHALL return counts and totals for the 0–30, 30–60, 60–90, and 90+ day buckets

#### Scenario: 90+ overdue triggers an alert email

- GIVEN an invoice crosses 90 days overdue
- WHEN the nightly overdue job runs
- THEN an alert email SHALL be sent to the supplier's primary contact
- AND a cross-supplier invoice request SHALL return 403

### Requirement: Invoice List and Detail UI

The system SHALL display invoices in a filterable list and render the expected payment date in the
detail view.

#### Scenario: Approved invoice shows the forecast prominently

- GIVEN a user with finance or admin role opens an approved invoice
- WHEN the detail renders
- THEN the expected payment date SHALL be shown in a highlighted green box
- AND the list SHALL show status badges and support filtering by status, date range, and amount

### Requirement: Age Analysis UI

The system SHALL render a stacked age-analysis bar with clickable buckets that filter the invoice
list.

#### Scenario: Clicking a bucket filters the list

- GIVEN the age-analysis bar is displayed with 0–30, 30–60, 60–90, and 90+ buckets
- WHEN the user clicks the 90+ bucket
- THEN the invoice list SHALL filter to invoices in that age range
- AND invoices more than 90 days overdue SHALL carry a red badge in the list

### Requirement: Contract List UI with Expiry Warnings

The system SHALL display contracts sorted by nearest expiry and badge those within 90 days.

#### Scenario: Expiring contract shows a warning badge

- GIVEN a user with contracts or admin role opens the Contracts tab
- WHEN a contract has `renewalWarning` = true
- THEN the row SHALL show an orange "Vervalt over [n] dagen" badge and a highlighted background
- AND contracts SHALL be sorted by end date with the nearest expiry first

### Requirement: Renewal Request UI

The system SHALL offer a renewal-request action for eligible contracts and confirm submission.

#### Scenario: Requesting renewal confirms and disables the button

- GIVEN a manual-renewal contract within 90 days of expiry
- WHEN the user clicks "Verlenging aanvragen" and confirms in the modal
- THEN the renewal request SHALL be submitted to the backend
- AND the button SHALL change to "Verlenging aangevraagd op [date]" and become disabled

### Requirement: Supplier Message Sending and Routing

The system SHALL create an immutable inbound message and notify the case handler.

#### Scenario: Sending a message notifies the handler

- GIVEN a supplier viewing one of its own cases
- WHEN it submits a message with optional attachments (≤5 files, ≤10 MB each)
- THEN a `SupplierMessage` with `direction` = inbound SHALL be created and a notification sent to
  the handler's Procest inbox and email
- AND the supplier SHALL see a success confirmation and the message in the thread
- AND a supplier SHALL NOT be able to message a case outside its scope

### Requirement: Handler Response and Immutable Thread

The system SHALL record handler responses as outbound messages, notify the supplier, and keep the
thread immutable.

#### Scenario: Handler response appears in the supplier thread

- GIVEN a handler responds to a supplier message in Procest
- WHEN the response is recorded
- THEN a `SupplierMessage` with `direction` = outbound SHALL be created and the supplier emailed
- AND the thread SHALL display messages chronologically with sender and timestamp
- AND existing messages SHALL remain immutable (no update path) for audit and compliance

### Requirement: Auto-Applied Address and Contact Updates

The system SHALL apply address and contact-person changes immediately and audit them.

#### Scenario: Address change applies immediately

- GIVEN an admin submits a valid address change
- WHEN the change is saved
- THEN the Supplier record SHALL be updated immediately, an audit entry written, and a
  confirmation email sent to the primary contact

### Requirement: IBAN Change Requires Re-Auth and 4-Eyes Approval

The system SHALL hold an IBAN change behind re-authentication and a 4-eyes Procest workflow before
it takes effect.

#### Scenario: IBAN change opens a 4-eyes case and is not applied yet

- GIVEN a user submits an IBAN change after re-authenticating
- WHEN the request is submitted
- THEN a Procest zaak of type `Leverancier-IBAN-wijziging` with a 4-eyes workflow SHALL be created
- AND the Supplier IBAN SHALL NOT change until both reviewers approve
- AND on approval the IBAN SHALL be updated and the supplier notified; on rejection the old IBAN
  SHALL remain active

### Requirement: Accreditation Submission for Verification

The system SHALL submit SBI/accreditation changes for verification without auto-applying them.

#### Scenario: Accreditation change is queued for verification

- GIVEN a user submits an accreditation change with proof attachments
- WHEN the request is submitted
- THEN a Procest zaak of type `Leverancier-accreditatie-verificatie` SHALL be created and the
  change SHALL NOT be applied until the municipal team approves

### Requirement: Nightly KPI Aggregation

The system SHALL compute four KPI metrics per supplier per month with a municipal benchmark and
mark months with insufficient data.

#### Scenario: Metrics and benchmark are computed for a month

- GIVEN the KPI aggregation job runs nightly at 02:00 UTC
- WHEN it processes a supplier's prior month
- THEN it SHALL store a `SupplierKPI` record with avg payment days, on-time %, dispute rate, and
  compliance score, plus the municipal benchmark for the same period

#### Scenario: Insufficient data is flagged

- GIVEN a supplier has fewer than 3 invoices in a month
- WHEN the aggregation runs
- THEN that month's metric SHALL be marked `sufficientData` = false and excluded from the trend

### Requirement: KPI Snapshot, Trends, and Export API

The system SHALL serve the current snapshot, 12-month trends, and a CSV export over a scoped API.

#### Scenario: CSV export contains 12 months per metric

- GIVEN a supplier with 12 months of data requests an export
- WHEN the export endpoint responds
- THEN the CSV SHALL contain 48 data rows (4 metrics × 12 months) plus a header, with values to one
  decimal and ISO `YYYY-MM` dates
- AND the export event SHALL be audit-logged

### Requirement: KPI Cards and Trend Charts

The system SHALL display four metric cards with benchmark comparison and 12-month trend charts.

#### Scenario: Cards render with benchmark and trends

- GIVEN a user with admin or finance role opens the KPI Dashboard
- WHEN it loads from the KPI API
- THEN four cards SHALL show avg payment days, on-time %, dispute rate, and compliance score
- AND each card SHALL show the municipal benchmark comparison and a 12-month trend chart with month
  labels and hover tooltips

#### Scenario: Insufficient-data months are skipped from the chart

- GIVEN a month is flagged `sufficientData` = false
- WHEN the trend chart renders
- THEN that month SHALL be skipped from the chart and labelled insufficient

### Requirement: CSV Export UI

The system SHALL provide a CSV export action on the KPI dashboard.

#### Scenario: Export downloads the CSV

- GIVEN the KPI dashboard is displayed
- WHEN the user clicks "Export to CSV"
- THEN the browser SHALL download the CSV served by the KPI export endpoint

### Requirement: Portal Shell and Role-Aware Navigation

The system SHALL render a consistent portal layout whose navigation shows only the tabs allowed for
the user's role.

#### Scenario: Navigation follows the user role

- GIVEN an authenticated user opens the portal
- WHEN the shell renders
- THEN the layout SHALL show the header, role-aware nav, and a profile menu with name, role, "Mijn
  Gegevens", and logout
- AND only the tabs permitted for the user's role SHALL be visible

### Requirement: Dashboard Summary Cards

The system SHALL render at-a-glance summary cards aggregating the supplier's key figures.

#### Scenario: Summary cards show counts and link to features

- GIVEN the dashboard loads for an authenticated supplier
- WHEN the summary renders
- THEN four cards SHALL show open tenders, unpaid invoices, expiring contracts, and a KPI headline
  with counts/status badges
- AND each card SHALL link into its corresponding feature view
- AND clicking logout SHALL destroy the session and redirect to login

### Requirement: End-to-End Supplier Journeys Are Verified

The system SHALL pass automated end-to-end tests covering the principal supplier journeys.

#### Scenario: Core journeys pass end-to-end

- GIVEN the full portal is deployed to a test environment
- WHEN the E2E suite runs
- THEN the login→dashboard→tender→download→logout journey SHALL pass
- AND the admin-invite→activate→role-scoped-tabs journey SHALL pass
- AND the invoice→message→response and contract-renewal-request journeys SHALL pass

### Requirement: Accessibility and Security Audit Pass

The system SHALL pass a WCAG 2.1 AA audit and a security audit before release.

#### Scenario: Audits gate the release

- GIVEN the portal is feature-complete
- WHEN the accessibility audit runs
- THEN keyboard navigation, ARIA labelling, and contrast ≥4.5:1 SHALL pass
- AND the security audit SHALL confirm no cross-supplier data leakage, masked PII in logs, enforced
  financial re-auth, rate limiting, and CSRF protection on all mutations

### Requirement: Portal Documentation Is Published

The system SHALL ship API, deployment, and user documentation plus a release/rollback plan.

#### Scenario: Documentation set is complete

- GIVEN the portal is ready for release
- WHEN the documentation is reviewed
- THEN an API reference, deployment guide, user guide, and a release + rollback plan SHALL exist

### Requirement: Tender List UI

The system SHALL display the supplier's tenders in a sortable, filterable list with status badges.

#### Scenario: List renders with badges and supports filtering

- GIVEN a user with finance, sales, or admin role opens the Tenders tab
- WHEN the list loads from the tender API
- THEN each tender SHALL show its title, status badge, submitted date, and value
- AND the user SHALL be able to sort by clicking column headers and filter by status, date range,
  and a case-insensitive title search

### Requirement: Tender Detail UI

The system SHALL render status-specific tender detail and document download controls.

#### Scenario: Detail renders conditional award/rejection sections

- GIVEN a user opens a tender detail
- WHEN the tender is awarded
- THEN the award date and award-letter download button SHALL be shown
- AND WHEN the tender is rejected THEN the rejection reason, appeal deadline, and anonymized
  evaluation-report download button SHALL be shown

