# Design: leverancier-zaakportaal

## Architecture

The supplier portal is a Vue/Nuxt SPA that authenticates users via eHerkenning (through OpenConnector) and displays case data retrieved from Procest via OpenRegister REST API. The portal enforces supplier scoping at every layer: login (eHerkenning KvK claim must match a Supplier record), API queries (all case lookups filtered by supplierRef), and UI (role-based tab visibility). No direct database access; all data flows through OpenRegister/Procest REST APIs and event streams.

```
┌──────────────────┐
│   Supplier       │
│    Portal        │ (Vue/Nuxt SPA)
│  leveranciers.   │
│  gemeente.nl     │
└────────┬─────────┘
         │
         ├─ eHerkenning login flow
         │  (OpenConnector broker)
         │
    ┌────▼──────┐
    │ OpenReg.  │ (REST API)
    │ /supplier │
    │ /case     │
    │ /message  │
    └─────┬─────┘
          │
    ┌─────▼──────────┐
    │    Procest     │
    │  Case Storage  │
    │  (zaak-based)  │
    └────────────────┘
          ↓
    ┌──────────────────┐
    │ Decidesk, Shillinq│
    │ (for payment data)│
    └──────────────────┘
```

## Service Layout

### Portal Frontend (Vue/Nuxt)

- **DashboardView** — Supplier summary: open tenders, expiring contracts, unpaid invoices, KPI headline
- **TenderView** — Searchable tender list; detail view with status, award info, evaluation report download
- **InvoiceView** — Invoice list with status, payment forecast, age analysis, dispute management
- **ContractView** — Active contracts with end dates, warning badges, renewal request button
- **MessageView** — Per-case messaging interface; displays conversation history with case handler
- **ProfileView** — Master data (address, IBAN, contact person); self-service update forms with validation
- **KPIView** — KPI dashboard with 12-month trends and CSV export

### Backend Services (Procest / OpenRegister)

#### SupplierAuthService
- eHerkenning login handler: exchanges authorization code for ID token
- Validates KvK claim against Supplier register (via OpenConnector → KvK API)
- Creates or links SupplierUser record
- Issues session token with 2-hour TTL
- Re-authentication required for financial mutations (invoice viewing, IBAN changes)
- Methods: `authenticateViaEHerkenning()`, `validateKvKClaim()`, `createOrLinkSupplierUser()`, `issueSessionToken()`

#### SupplierScopeService
- Filters all case queries by supplier scope
- Enforces that suppliers see only their own tenders, contracts, invoices
- Manages case relationship lookup (finds all SupplierTender, SupplierContract, SupplierInvoice related to a supplier)
- Methods: `getSupplierCases()`, `filterCasesBySupplier()`, `validateSupplierAccess(caseId, supplierId)`

#### SupplierUserManagementService
- Invitation workflow: supplier admin sends invite link to colleague
- Colleague clicks link, authenticates via eHerkenning, activates account with assigned role
- Role-based access control: admin, finance, contracts, sales, read_only
- Admin can revoke access or change roles
- Methods: `inviteSupplierUser()`, `activateSupplierUser()`, `updateUserRole()`, `revokeAccess()`

#### TenderVisibilityService
- Exposes supplier-submitted tenders via SupplierTender view (links zaak → tender with supplier-scoped attributes)
- Tracks status: submitted → evaluating → awarded/rejected → (appeal possible)
- Provides anonymized evaluation report download (per Aanbestedingswet 2.130)
- Methods: `getTenderStatus()`, `getEvaluationReport()`, `getAwardLetter()`, `getAppealDeadline()`

#### InvoicePaymentForecastService
- Joins SupplierInvoice + Decidesk mandate routing to calculate `expectedPaymentDate`
- Logic: `invoice.invoiceDate` + mandate_routing_delay + payment_terms_days = forecast
- Updates forecast as payment terms or routing changes
- Detects overdue invoices and sends alert
- Methods: `calculateExpectedPaymentDate()`, `updateForecast()`, `getAgeAnalysis()`, `flagOverdueInvoices()`

#### ContractRenewalService
- Scans SupplierContract entities monthly; identifies contracts within 90 days of endDate
- Marks contracts with `renewalWarning` = true and `daysUntilExpiry`
- "Request Renewal" button creates a Procest task ("Leverancier-contractverlenging-verzoek")
- Methods: `scanExpiringContracts()`, `flagContractWithinThreshold()`, `requestRenewal()`

#### SupplierMessageService
- Creates SupplierMessage records linked to case + supplier
- Routes message to case handler's Procest inbox (via notification queue)
- Handler responds; response sent back to supplier via email + portal notification
- Maintains conversation history on case for audit trail
- Methods: `sendMessage()`, `addResponse()`, `getConversationHistory()`, `notifyHandler()`

#### SupplierMasterDataMutationService
- Handles "Mijn Gegevens" updates: address, contact person, IBAN, VAT number
- Address / contact person: auto-applied with audit log
- IBAN: requires re-auth + bank verification + creates "Leverancier-mutatie" Procest zaak for 4-eyes approval
- SBI codes / accreditations: submitted for verification (not auto-applied)
- Methods: `updateAddress()`, `updateIBAN()`, `updateContactPerson()`, `submitForVerification()`, `auditMutation()`

#### SupplierKPIAggregationService
- Batched nightly job: aggregates SupplierKPI metrics from last 12 months
- Calculates: avg payment days (own vs. municipal average), on-time payment %, dispute rate, contract compliance score
- Generates trend data for charting (12 data points: monthly)
- Methods: `aggregateKPIs()`, `calculatePaymentDaysMetric()`, `calculateOnTimePercentage()`, `calculateDisputeRate()`, `calculateComplianceScore()`

### API Layer (REST)

#### Supplier Portal Endpoints

**Authentication**
- `GET /api/supplier-portal/auth/eherkenning-login` — Initiate eHerkenning redirect
- `GET /api/supplier-portal/auth/callback?code=...` — Handle eHerkenning callback
- `POST /api/supplier-portal/auth/logout` — Destroy session
- `POST /api/supplier-portal/auth/refresh` — Refresh session (2-hour TTL)

**Dashboard & Overview**
- `GET /api/supplier-portal/dashboard` — Summary (open tenders, unpaid invoices, expiring contracts, latest KPI)

**Tenders**
- `GET /api/supplier-portal/tenders` — List supplier's tenders (filterable: status, date range)
- `GET /api/supplier-portal/tenders/{tenderId}` — Tender detail (status, award info, motivation)
- `GET /api/supplier-portal/tenders/{tenderId}/evaluation-report` — Download anonymized evaluation report (PDF)
- `GET /api/supplier-portal/tenders/{tenderId}/award-letter` — Download award letter (if awarded)

**Invoices**
- `GET /api/supplier-portal/invoices` — List supplier's invoices (filterable: status, date range)
- `GET /api/supplier-portal/invoices/{invoiceId}` — Invoice detail (status, payment forecast, age, dispute info)
- `POST /api/supplier-portal/invoices/{invoiceId}/dispute` — Submit payment dispute (creates message/task)
- `GET /api/supplier-portal/invoices/age-analysis` — Overdue buckets (0–30, 30–60, 60–90, 90+ days)

**Contracts**
- `GET /api/supplier-portal/contracts` — List active contracts (filterable: expiry, account manager)
- `GET /api/supplier-portal/contracts/{contractId}` — Contract detail (terms, renewal option, account manager contact)
- `POST /api/supplier-portal/contracts/{contractId}/request-renewal` — Create renewal request task in Procest

**Messages**
- `GET /api/supplier-portal/messages?caseId={caseId}` — Conversation history for a case
- `POST /api/supplier-portal/messages` — Send new message (payload: {caseId, body, attachmentRefs[]})
- `GET /api/supplier-portal/messages/{messageId}` — Single message detail

**Master Data (Mijn Gegevens)**
- `GET /api/supplier-portal/profile` — Current supplier data (address, IBAN, contact person, accreditations)
- `POST /api/supplier-portal/profile/address` — Update address (auto-applied, audit logged)
- `POST /api/supplier-portal/profile/iban` — Update IBAN (requires re-auth, verification, 4-eyes approval)
- `POST /api/supplier-portal/profile/contact-person` — Update contact person (auto-applied, audit logged)
- `POST /api/supplier-portal/profile/accreditations` — Submit for verification (not auto-applied)

**Users (Multi-Account Management)**
- `GET /api/supplier-portal/users` — List team members (admin only)
- `POST /api/supplier-portal/users/invite` — Send invitation (email + role)
- `POST /api/supplier-portal/users/{userId}/role` — Update user role (admin only)
- `DELETE /api/supplier-portal/users/{userId}` — Revoke access (admin only)

**KPIs**
- `GET /api/supplier-portal/kpis` — Current KPI snapshot (avg payment days, on-time %, dispute rate, compliance score)
- `GET /api/supplier-portal/kpis/trends` — 12-month trend data (JSON: [month, value] pairs)
- `GET /api/supplier-portal/kpis/export` → CSV download of full history

## Data Model

### Core Entities (OpenRegister Schemas)

**Supplier**
- `id`, `kvkNumber` (unique, validated via KvK API), `legalName`, `tradeName`, `addressRef` (full address object), `primaryContactRef` (person contact), `iban`, `vatNumber`, `sbiCodes[]` (JSON array of SBI classification codes), `accreditations[]` (PSO, MVO-Prestatieladder, ISO-9001, etc.), `status` (active/inactive/blacklisted), `onboardedAt`, `lastVerifiedAt` (when KvK data was last validated)

**SupplierUser**
- `id`, `supplierRef` (reference to Supplier), `userRef` (Nextcloud user UID or external person ID), `role` (admin/finance/contracts/sales/read_only), `eherkenningLevel` (2 or 3), `addedBy` (who invited this user), `addedAt`, `lastLoginAt`, `mfaEnabled`, `status` (active/invited/revoked)

**SupplierTender** (lookup view on zaak)
- `id`, `caseRef` (reference to Procest zaak), `supplierRef` (reference to Supplier), `tenderId` (procurement system ID), `title`, `submittedAt`, `status` (draft/submitted/under_evaluation/awarded/rejected/withdrawn), `awardDate`, `rejectionReason` (per Aanbestedingswet 2.130, required if status=rejected), `appealDeadline`, `contractValue`, `evaluationReportRef` (document reference, anonymized), `awardLetterRef` (document reference)

**SupplierContract** (lookup view on zaak)
- `id`, `caseRef` (reference to Procest zaak), `supplierRef` (reference to Supplier), `contractNumber`, `subject`, `startDate`, `endDate`, `renewalOption` (auto_renewal / manual_request / none), `noticePeriodDays`, `contractValue`, `accountManagerRef` (municipal contact), `documentRef`, `renewalWarning` (boolean, true if within 90 days of expiry), `daysUntilExpiry` (calculated field)

**SupplierInvoice** (lookup view on zaak)
- `id`, `caseRef` (reference to Procest zaak), `supplierRef` (reference to Supplier), `invoiceNumber`, `invoiceDate`, `dueDate`, `amount` (EUR), `vatAmount` (EUR), `status` (received/under_review/approved/paid/disputed/rejected), `expectedPaymentDate` (calculated from mandate routing + payment terms), `actualPaymentDate`, `disputeReason` (if status=disputed), `ageInDays` (calculated), `ageBucket` (0-30 / 30-60 / 60-90 / 90+)

**SupplierMessage**
- `id`, `supplierRef` (reference to Supplier), `caseRef` (reference to Procest zaak), `direction` (inbound/outbound), `subject`, `body`, `attachmentRefs[]` (Nextcloud file IDs), `sentBy` (user ID), `sentAt`, `readAt`, `responseMessageRef` (link to response message if this is a question)

**SupplierKPI**
- `id`, `supplierRef` (reference to Supplier), `metric` (avg_payment_days / on_time_payment_pct / dispute_rate / contract_compliance_score), `value` (number), `period` (YYYY-MM), `calculatedAt`, `benchmark` (municipal average for comparison, optional)

### Relations

- `supplier` → `supplierUser` (one-to-many)
- `supplier` → `supplierTender` (one-to-many)
- `supplier` → `supplierContract` (one-to-many)
- `supplier` → `supplierInvoice` (one-to-many)
- `supplier` → `supplierMessage` (one-to-many)
- `supplier` → `supplierKPI` (one-to-many)
- `supplierTender` → `case` (many-to-one)
- `supplierContract` → `case` (many-to-one)
- `supplierInvoice` → `case` (many-to-one)
- `supplierMessage` → `case` (many-to-one)

## Seed Data

### Suppliers (3 examples with Dutch names)

**1. Constructiebedrijf Van der Berg BV**
- `kvkNumber`: 12345678
- `legalName`: Van der Berg Bouwbedrijven BV
- `tradeName`: Van der Berg
- `status`: active
- `sbiCodes`: [41100] (Verbouw van gebouwen)
- `accreditations`: [ISO-9001, VCA-VNA]
- `onboardedAt`: 2025-06-15

**2. ICT Consultancy Jansen & Co**
- `kvkNumber`: 87654321
- `legalName`: Jansen & Co ICT Diensten BV
- `tradeName`: J&C ICT
- `status`: active
- `sbiCodes`: [62010] (Computerprogrammering)
- `accreditations`: [ISO-27001, ISO-9001]
- `onboardedAt`: 2025-08-01

**3. Zakelijk Schoonmaak NV**
- `kvkNumber`: 55443322
- `legalName`: Zakelijk Schoonmaak Nederland NV
- `tradeName`: ZS Nederland
- `status`: active
- `sbiCodes`: [81100] (Schoonmaakdiensten gebouwen)
- `accreditations`: [ISO-14001]
- `onboardedAt`: 2025-09-10

### SupplierUsers (multi-user per supplier)

**Van der Berg BV:**
1. Marcus van der Berg (admin) — eHerkenning level 3, lastLogin: 2026-05-20
2. Sarah Jansen (finance) — eHerkenning level 2, lastLogin: 2026-05-18

**Jansen & Co:**
1. Robert Jansen (admin) — eHerkenning level 3, lastLogin: 2026-05-19
2. Petra Hendrix (contracts) — eHerkenning level 2, lastLogin: 2026-05-15

**ZS Nederland:**
1. Angelique Groot (admin) — eHerkenning level 2, lastLogin: 2026-05-21

### SupplierTenders (3 awarded, 2 pending, 1 rejected)

1. **2026-TEN-0042** — "Renovatie gemeentehuis Amsterdam" — Van der Berg BV — Status: **awarded** — AwardDate: 2026-04-15 — ContractValue: €450,000
2. **2026-TEN-0051** — "ICT-infrastructuuraanpassing 2026" — Jansen & Co — Status: **under_evaluation** — SubmittedAt: 2026-03-22 — ContractValue: €85,000
3. **2026-TEN-0053** — "Schoonmaak gemeentelijke kantoren 2026–2027" — ZS Nederland — Status: **submitted** — SubmittedAt: 2026-05-10 — ContractValue: €120,000
4. **2026-TEN-0048** — "Renovatie schoolgebouw Rotterdam" — Van der Berg BV — Status: **rejected** — RejectionReason: "Kostprijs overschreden, technische kwaliteit onvoldoende" — AppealDeadline: 2026-06-10
5. **2026-TEN-0045** — "Interim staffing IT-ondersteuning" — Jansen & Co — Status: **awarded** — AwardDate: 2026-02-28 — ContractValue: €320,000

### SupplierContracts (3 active, 1 expiring soon)

1. **CNT-2024-0108** — "Schoonmaakdiensten gemeentehuis Amsterdam 2024–2026" — ZS Nederland — StartDate: 2024-01-01 — EndDate: 2026-09-30 — ContractValue: €95,000 — RenewalWarning: true (daysUntilExpiry: 131 days) — RenewalOption: manual_request
2. **CNT-2025-0019** — "ICT-support en onderhoud 2025–2027" — Jansen & Co — StartDate: 2025-01-15 — EndDate: 2027-12-31 — ContractValue: €240,000 — RenewalWarning: false — RenewalOption: auto_renewal
3. **CNT-2026-0033** — "Bouwtoezicht 2026–2028" — Van der Berg BV — StartDate: 2026-03-01 — EndDate: 2028-02-28 — ContractValue: €175,000 — RenewalWarning: false — RenewalOption: manual_request
4. **CNT-2023-0077** — "Faciliteitsmanagement kantoorgebouw 2023–2026" — Van der Berg BV — StartDate: 2023-01-01 — EndDate: **2026-06-30** — ContractValue: €320,000 — RenewalWarning: **true** (daysUntilExpiry: 39 days) — RenewalOption: manual_request

### SupplierInvoices (5 samples: paid, approved, under review, disputed, overdue)

1. **INV-2026-0512** — Van der Berg BV — InvoiceDate: 2026-04-15 — Amount: €45,000 — Status: **paid** — ActualPaymentDate: 2026-05-12
2. **INV-2026-0518** — Jansen & Co — InvoiceDate: 2026-05-01 — Amount: €8,500 — Status: **approved** — ExpectedPaymentDate: 2026-06-05 (invoice date + 30 days payment terms + 5 days mandate routing)
3. **INV-2026-0521** — ZS Nederland — InvoiceDate: 2026-05-10 — Amount: €12,000 — Status: **under_review** — ExpectedPaymentDate: TBD (pending approval)
4. **INV-2026-0495** — Van der Berg BV — InvoiceDate: 2026-04-01 — Amount: €22,500 — Status: **disputed** — DisputeReason: "Werkzaamheden niet conform offerte; facturering onduidelijk" — AgeBucket: 90+
5. **INV-2026-0505** — Jansen & Co — InvoiceDate: 2026-03-28 — Amount: €18,000 — Status: **approved** — ActualPaymentDate: null — ExpectedPaymentDate: 2026-05-07 (now 15 days overdue) — AgeBucket: 30-60

### SupplierMessages (conversation thread)

**2026-MSG-0031** (message thread on tender 2026-TEN-0048 "Renovatie schoolgebouw Rotterdam")
1. SupplierMessage: Marcus van der Berg (Inbound) — Sent: 2026-05-08 — Subject: "Bezwaar tegen afwijzing" — Body: "Ik ben zeer teleurgesteld over de afwijzing. Onze prijs lag binnen budget en onze kwaliteit is bewezen. Graag een toelichting op de afwijzingsgrond en deadline bezwaarschrift." — Status: Read
2. SupplierMessage: Ambtenaar Procest (Outbound) — Sent: 2026-05-12 — Subject: "RE: Bezwaar tegen afwijzing" — Body: "Dank voor uw bericht. De afwijzing is gebaseerd op artikel 2.130 Aanbestedingswet. De volledige motivering en beoordelingsverslag (geanonimiseerd) zijn bijgevoegd. U heeft tot 2026-06-10 om bezwaar in te dienen." — Attachments: [evaluation_report_anonymized.pdf, rejection_motivation.pdf]

## API Design

See Service Layout section above for full REST endpoint listing. Key patterns:

- **Supplier Scoping**: All endpoints automatically filter results to logged-in supplier via `SupplierAuthService.getCurrentSupplierRef()`
- **Role-Based Access**: Finance role can access `/invoices`; Sales role can access `/tenders` and `/contracts`; Read-only role sees all tabs but cannot initiate actions
- **Payment Forecast Calculation**: `/invoices/{id}` includes `expectedPaymentDate` calculated by joining with Decidesk mandate routing
- **Pagination**: List endpoints support `?limit=50&offset=0`
- **Filtering**: Tender/Invoice/Contract lists support `?status=...&dateFrom=...&dateTo=...`
- **Real-Time Updates**: WebSocket subscription available for new messages and payment status changes (optional; polling fallback available)

## Reuse Analysis

| Service/Component | Used For | Notes |
|---|---|---|
| `Procest / zaak` | Case storage (tender, contract, invoice as zaak subtypes) | No custom case logic; portal is read-only view |
| `OpenRegister REST API` | CRUD on Supplier, SupplierUser, SupplierMessage, SupplierKPI entities | No custom form builders; standard OpenRegister usage |
| `OpenConnector eHerkenning` | Login broker | No custom OAuth code; uses existing OpenConnector eHerkenning service |
| `KvK Handelsregister API` | Supplier validation on login | Already integrated in OpenConnector; portal consumes validated claims |
| `Decidesk mandate routing` | Payment date forecast calculation | Portal queries mandate routing; no custom business rules |
| `Notification queue (Pipelinq)` | SupplierMessage → case handler inbox, response → supplier email | Reuse existing event-based notification; trigger on SupplierMessage creation |
| `Docudesk / Nextcloud Files` | Evaluation report and award letter downloads | Portal serves pre-generated PDFs; no custom generation |
| `NL Design System` | Portal UX (buttons, forms, tables, modals) | Reuse existing component library; no custom CSS |

No deduplication issues found; all custom services are supplier-specific data aggregation (KPI calculation) or workflow orchestration (message routing, renewal requests) not provided by existing systems.

## Integration Boundaries

- **Portal ↔ Procest** — All supplier-visible data (tenders, contracts, invoices) stored as zaak entities in Procest; portal reads via OpenRegister REST API with supplier-scoped filters
- **Portal ↔ OpenConnector** — eHerkenning authentication via OAuth 2.0 code exchange; KvK claim validation delegated to OpenConnector KvK API client
- **Portal ↔ Decidesk** — Payment date forecast: portal queries Decidesk mandate routing (via OpenRegister or direct API) to calculate expected payment date
- **Portal ↔ Pipelinq** — Workflow triggers: "Request Renewal" button and "Leverancier-mutatie" master data updates create Procest tasks via Pipelinq event dispatch
- **Portal ↔ Notification System** — SupplierMessage creation triggers email notification to case handler; handler response triggers portal notification + email to supplier
- **Portal ↔ Docudesk** — Evaluation reports and award letters are pre-generated and stored in Nextcloud; portal serves download links

## Standards Alignment

- **eHerkenning 2+/3** — Corporate authentication per Dutch identity standards; portal enforces minimum niveau 2
- **KvK Handelsregister** — Supplier data validated against Chamber of Commerce registry on login
- **Aanbestedingswet 2012 art. 2.127 (award)** — Award information displayed within legal timeline
- **Aanbestedingswet 2012 art. 2.130 (motivation)** — Rejection motivation and anonymized evaluation report provided to bidders
- **EU Directive 2014/24 (procurement)** — Tender status and award information compliant with transparency requirements
- **UBL 2.1 / Peppol BIS Billing 3.0** — E-invoice data (invoices in portal) received and validated per NLCIUS standard
- **NL Design System** — Portal UI uses design tokens, components, and patterns from official government design system
- **WCAG 2.1 AA** — Portal must pass accessibility audit (keyboard navigation, screen reader support, color contrast)
- **AVG art. 25 (Privacy by Design)** — Supplier scoping enforced at every layer; no cross-supplier data leakage
- **AVG art. 32 (Data Security)** — 2-hour session TTL, re-authentication for financial actions, audit logging of all mutations
- **Common Ground laag 5** — Portal is a consumer of APIs (Procest/OpenRegister); no public-facing APIs defined in this spec
