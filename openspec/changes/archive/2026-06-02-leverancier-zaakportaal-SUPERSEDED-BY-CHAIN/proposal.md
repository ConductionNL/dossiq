> SUPERSEDED 2026-06-02 (ADR-032): decomposed into leverancier-zaakportaal-01..16

# Proposal: leverancier-zaakportaal

## Summary

Build a specialized portal where suppliers (leveranciers) gain real-time visibility into their cases and business with municipalities: tender submissions with status and award information, active contracts with expiration tracking, invoices with payment forecast, complaint management, and KPI performance metrics. Authentication uses eHerkenning niveau 2+ (corporate entity) with multi-user, role-based access per supplier. This eliminates 40–60% of inbound supplier contact traffic while satisfying transparency mandates around public procurement.

## Why

Dutch municipalities have hundreds to thousands of suppliers (contractors, IT vendors, cleaning services, lawyers, accountants, care providers) who need constant visibility: "Where is my invoice?", "What's the status of my tender bid?", "Has the municipality signed my contract renewal?" Today suppliers must call, email, or file forms to get answers—slow, costly, frustrating. In parallel to the existing citizen portal (`zaakportaal`), a supplier-focused portal provides 24/7 self-service access, reduces manual municipal processing (40–60% KCC call reduction proven at Eindhoven and The Hague), satisfies transparency requirements (Aanbestedingswet 2012 art. 2.130), and improves supplier NPS.

## What Changes

1. **Supplier Portal Core** — Web application at `leveranciers.gemeente.nl` with dashboard, role-based tabs (Finance, Contracts, Sales, Read-Only), and case search
2. **eHerkenning Integration** — Login via eHerkenning niveau 2+ (KvK-based corporate auth) with automatic Supplier ↔ SupplierUser mapping
3. **Multi-User Access with Role-Based Control** — Supplier admins invite team members (finance, sales, contracts, read-only) with email + role activation; different roles see different tabs
4. **Real-Time Tender Dashboard** — Searchable list of tenders supplier has bid on; each tender shows status (submitted / evaluating / awarded / rejected / withdrawn), award date, rejection motivation (per Aanbestedingswet 2.130), award letter download, appeal deadline
5. **Invoice Tracking with Cash Flow Forecast** — Supplier sees all invoices with status (received / review / approved / paid / disputed / rejected), expected payment date (auto-calculated from mandate routing + payment terms), age analysis (0–30 / 30–60 / 60–90 / 90+ days overdue), and dispute management
6. **Contract Management with Lifecycle Warnings** — All active contracts visible with end date, value, account manager, renewal options; contracts within 90 days of expiry flagged with warning badge; one-click "Request Renewal" creates a task in Procest
7. **Secure Messaging per Case** — Supplier sends question about a specific case; message appears in case handler's Procest inbox; async email notifications on responses; full audit trail
8. **Self-Service Master Data Updates** — Address, IBAN, contact person changes initiated by supplier; routine changes (address, contact) processed automatically; IBAN changes require re-authentication + bank verification + 4-eyes review before activation
9. **KPI Dashboard** — 12-month trend view: average payment days (own vs. municipal average), on-time payment %, dispute rate, contract compliance score; data exportable to CSV

## Impact

- **Affected projects**: procest (primary case handler view), openregister (supplier ↔ case lookup), openconnector (eHerkenning broker, KvK API validation), decidesk (mandate routing for payment date calculation), pipelinq (workflow triggers for mutations and contract renewals), docudesk (evaluation report PDFs), shillinq (outbound invoice tracking), nldesian (portal UX consistency)
- **Code surface**: Portal frontend (Vue/Nuxt), supplier-scoped API endpoints, eHerkenning session handler, role-based UI layer, message queue integration for notifications, payment date calculator, KPI aggregation service
- **Dependencies**: REQUIRED: Procest (case management), OpenRegister (case storage), OpenConnector (eHerkenning/KvK), Decidesk (mandate routing); optional: Pipelinq (workflow automation), Docudesk (PDF generation)
- **Standards**: eHerkenning 2+/3 (Logius), KvK Handelsregister API (Kamers van Koophandel), Aanbestedingswet 2012 art. 2.127 (award), 2.130 (motivation), EU Directive 2014/24 (procurement), UBL 2.1 / Peppol BIS Billing (e-invoice), NL Design System (UX), WCAG 2.1 AA (accessibility), AVG art. 25/32 (privacy), TIBR / Wet Open Overheid (transparency)

## Scope

### In Scope — Core Portal

- Supplier portal web application with dashboard and role-based tabs (Finance, Contracts, Sales, Read-Only)
- eHerkenning niveau 2+ login with automatic SupplierUser record creation and KvK validation
- Multi-user invitation workflow (email + role-based activation)
- Tender search, detail view, award letter download, appeal deadline tracking
- Invoice tracking with payment status, expected payment date, age analysis, dispute management
- Contract list with renewal warnings (90-day threshold) and contract renewal request workflow
- KPI dashboard with 12-month trends and CSV export
- Secure per-case messaging (supplier → handler → supplier with notifications)
- Master data self-service (address, IBAN, contact person) with approval workflow

### Out of Scope

- Procurement workflow design (covered by vth-workflow-configuration)
- Payment processing or fund transfers (Decidesk and Shillinq handle money; this portal is visibility only)
- eHerkenning infrastructure or KvK API client library (covered by OpenConnector)
- E-invoice receipt and validation (covered by OpenConnector's Peppol ingest)
- Mandate routing algorithm (covered by Decidesk)
- Case management workflow (Procest handles that; portal is read-only except for messages and data updates)
- MKB dashboard or public procurement search (separate initiatives; this is supplier-facing only)

## Dependencies

- **Procest** (REQUIRED) — All supplier-visible cases (tenders, contracts, invoices) are stored as zaak subtypes in Procest; portal reads via OpenRegister REST
- **OpenRegister** (REQUIRED) — Supplier entity storage, case lookups filtered to supplier scope, relations management
- **OpenConnector** (REQUIRED) — eHerkenning broker for login, KvK API for supplier validation, e-invoice ingestion (invoices appear in portal after ingest)
- **Decidesk** (REQUIRED for invoice payment date feature) — Mandate routing determines expected payment date (invoice + payment terms = forecast)
- **Pipelinq** (recommended) — Workflow triggers for "Leverancier-mutatie" (master data change) and "Contract-verlengingsverzoek" (renewal request)
- **Docudesk** (optional) — PDF generation of anonymized evaluation reports and award letters
- **Shillinq** (optional, for completeness) — Outbound invoices from municipality to supplier (e.g., penalties, cost recovery)
- **NL Design** (REQUIRED) — Portal UX must match municipality portal look-and-feel (Conduction/NL Design System)

## Acceptance Criteria

1. GIVEN a supplier logs in via eHerkenning niveau 2+, WHEN the system validates the KvK claim, THEN the Supplier record is auto-located or created, a SupplierUser is created for this individual, and a session opens with 2-hour TTL and re-auth required for financial mutations
2. GIVEN a supplier admin invites a colleague with email + role, WHEN the colleague clicks the activation link and authenticates via eHerkenning, THEN a SupplierUser record is created with the assigned role and the team member can access the portal
3. GIVEN a supplier searches the Tender tab, WHEN they view a specific tender they bid on, THEN the status (submitted / evaluating / awarded / rejected / withdrawn), award date, contract value, and rejection motivation (if applicable, per Aanbestedingswet 2.130) are displayed, plus an appeal deadline (20 days) and download link for anonymized evaluation report
4. GIVEN a supplier opens the Invoice tab, WHEN they view an approved invoice, THEN the system displays the expected payment date (calculated from invoice date + mandate routing delay + payment term), age analysis buckets (0–30 / 30–60 / 60–90 / 90+ days), and actual payment date once paid
5. GIVEN a supplier views the Contract tab, WHEN a contract is within 90 days of expiry, THEN a warning badge is displayed and a "Request Renewal" button opens a task in Procest
6. GIVEN a supplier sends a message about a case, WHEN the message is submitted, THEN a SupplierMessage record is created, the message appears in the case handler's Procest inbox, and the handler receives an email notification with a secure response link
7. GIVEN a supplier updates their IBAN in "Mijn Gegevens", WHEN the form is submitted, THEN the system requires re-authentication and verification against the known-bank-account protocol, and the change is held for 4-eyes approval before activation
8. GIVEN a supplier with 18 months of transaction history opens the KPI Dashboard, WHEN they view the metrics, THEN average payment days (own vs. municipal average), on-time payment %, dispute rate, and contract compliance score are shown with 12-month trends and an export to CSV button

## Business Drivers

- **Operational efficiency**: Reduce inbound calls from suppliers (~40–60% reduction documented at Eindhoven, The Hague)
- **Supplier satisfaction**: NPS improvement through 24/7 self-service and payment visibility
- **Compliance**: Automatic publication of tender status and rejection motivation per Aanbestedingswet 2012 art. 2.130
- **Cash flow transparency**: Suppliers can forecast payments and plan working capital
- **Risk reduction**: Audit trail for all supplier interactions; compliant data handling per GDPR art. 25/32
