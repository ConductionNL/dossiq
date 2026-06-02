# Specs: leverancier-zaakportaal

## Overview

Detailed requirements for the supplier portal, covering authentication, multi-user access, tender visibility, invoice tracking, contract management, messaging, master data updates, and KPI reporting.

---

## REQ-001: eHerkenning Login for Corporate Suppliers

**Purpose**: Authenticate suppliers via eHerkenning niveau 2+ (corporate entity) with automatic KvK validation and SupplierUser record creation.

### REQ-001-A: Login Initiation and Redirect

GIVEN a supplier visits `https://leveranciers.gemeente.nl`
WHEN they click "Inloggen met eHerkenning"
THEN the system redirects to the eHerkenning broker (OpenConnector) with:
- `response_type=code`
- `scope=openid profile kvk` (KvK claim required)
- `redirect_uri=https://leveranciers.gemeente.nl/auth/callback`

AND the browser session is initialized with a temporary state token to prevent CSRF attacks.

### REQ-001-B: KvK Claim Validation and Supplier Lookup

GIVEN eHerkenning returns an authorization code
WHEN the system exchanges the code for an ID token (via OpenConnector)
THEN the system:
- Extracts the `kvkNumber` claim from the JWT
- Looks up the Supplier record in OpenRegister by `kvkNumber`
- Validates the KvK number is valid (numeric, 8 digits, formatted with zero-padding)
- If Supplier exists and `status` = active, proceeds to step C
- If Supplier does not exist, displays error: "Uw organisatie is niet geregistreerd in het leveranciersregister. Neem contact op met [gemeente]."
- If Supplier exists but `status` = inactive/blacklisted, displays error: "Uw organisatie is niet actief / staat op de blacklist."

### REQ-001-C: SupplierUser Creation and Session Issuance

GIVEN the KvK claim is valid and Supplier is active
WHEN the system looks up an existing SupplierUser by (`supplierRef`, `userRef` from eHerkenning ID)
THEN:
- If SupplierUser exists: update `lastLoginAt` = now, proceed to D
- If SupplierUser does not exist: create new SupplierUser with:
  - `role` = read_only (default; will require admin approval or self-service assignment)
  - `eherkenningLevel` = extracted from eHerkenning token (2 or 3)
  - `addedBy` = "system" (auto-created on first login)
  - `addedAt` = now
  - `status` = active
  - Proceed to D

### REQ-001-D: Session Token Issuance with TTL and Re-Auth Flag

GIVEN SupplierUser is active
WHEN the system creates a session
THEN:
- Issue a session token (JWT or secure session cookie) with TTL = 2 hours
- Set a flag `requiresReAuthForFinancial` = true (mandates re-auth before viewing invoices or changing IBAN)
- Redirect browser to dashboard
- Display onboarding message if this is first login: "Welkom! Vraag uw administrator om u de juiste rollen toe te kennen voor uw werkzaamheden."

### REQ-001-E: Session Expiry and Token Refresh

GIVEN a user's session is within 15 minutes of expiry (1 hour 45 minutes elapsed)
WHEN they perform an action on the portal
THEN the system:
- Automatically refreshes the session token (no user action required)
- Resets the 2-hour clock
- Logs the refresh event for audit trail

GIVEN a user's session has expired
WHEN they attempt to access a protected route
THEN the system redirects to login page with message: "Uw sessie is verlopen. Gelieve opnieuw in te loggen."

---

## REQ-002: Multi-Account Management with Role-Based Access Control

**Purpose**: Allow supplier admins to invite team members and assign role-based portal access.

### REQ-002-A: User Invitation Workflow

GIVEN a SupplierUser with `role` = admin is logged in
WHEN they navigate to "Team" or "Mijn Gegevens" → "Team toevoegen"
THEN they see a form to:
- Enter email address of colleague
- Select role from dropdown: admin, finance, contracts, sales, read_only
- Add optional message
- Click "Uitnodiging versturen"

AND the system:
- Validates email format (standard RFC 5322)
- Checks if email is already invited or active (warn if duplicate)
- Creates a SupplierUser record with `status` = "invited"
- Generates unique activation token (secure, 64-char random string)
- Sends email with subject "Uitnodiging leveranciersportal [Gemeente]" containing:
  - Greeting with sender name
  - Instruction: "U bent uitgenodigd om toegang te krijgen tot het leveranciersportal. Klik op de link hieronder en log in met uw eHerkenning."
  - Clickable link: `https://leveranciers.gemeente.nl/activate?token={activation_token}`
  - Expiry notice: "Deze uitnodiging vervalt over 7 dagen."
  - Contact info for questions

### REQ-002-B: Activation via eHerkenning

GIVEN a colleague receives the invitation email
WHEN they click the activation link and are not yet logged in
THEN the system:
- Validates the activation token (not expired, not yet used)
- Displays page: "U gaat lid worden van [Supplier Legal Name] op het leveranciersportal"
- Redirects to eHerkenning login
- After eHerkenning login, validates that the logged-in user's KvK claim matches the invited Supplier
- If match: updates SupplierUser `status` = active, marks token as used, displays success message
- If mismatch: rejects activation with error: "Uw eHerkenning-account hoort niet bij de uitgenodigde organisatie."

### REQ-002-C: Role-Based Dashboard Tab Visibility

GIVEN a SupplierUser is logged in
WHEN the dashboard loads
THEN visible tabs are determined by `role`:

| Role | Dashboard | Tenders | Invoices | Contracts | Messages | Profile | Team (admin only) |
|------|-----------|---------|----------|-----------|----------|---------|-------------------|
| admin | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| finance | ✓ | ✗ | ✓ | ✗ | ✓ | ✓ (limited) | ✗ |
| contracts | ✓ | ✗ | ✗ | ✓ | ✓ | ✓ (limited) | ✗ |
| sales | ✓ | ✓ | ✗ | ✗ | ✓ | ✓ (limited) | ✗ |
| read_only | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ (limited) | ✗ |

Finance role Profile access limited to: address, contact person (not IBAN or bank details).

### REQ-002-D: Admin Role Management

GIVEN a SupplierUser with `role` = admin is in the Team tab
WHEN they view a team member
THEN they see:
- Member name, email, role, last login date
- Buttons: "Rol wijzigen", "Toegang intrekken"

WHEN they click "Rol wijzigen"
THEN a dropdown appears with options: admin, finance, contracts, sales, read_only
WHEN they select a new role and confirm
THEN:
- SupplierUser `role` is updated
- Audit log entry is created: "Admin [name] changed role of [member] from [old] to [new] at [timestamp]"
- Member receives email notification: "[Supplier name] has changed your role to [new role]."

WHEN they click "Toegang intrekken"
THEN after confirmation:
- SupplierUser `status` is set to revoked
- Session tokens for this user are invalidated
- Member receives email: "Your access to the [Supplier name] supplier portal has been revoked."

---

## REQ-003: Real-Time Tender Status Visibility

**Purpose**: Display all tenders a supplier has submitted with current status, award information, and legal information per Aanbestedingswet 2012 art. 2.130.

### REQ-003-A: Tender List and Search

GIVEN a user with finance, sales, or admin role opens the Tenders tab
WHEN the page loads
THEN the system displays:
- List of all tenders the supplier has submitted (via SupplierTender entities)
- Columns: Tender Title, Status, Submitted Date, Value (EUR), Award Date (if applicable)
- Sorting: by date (newest first) by default; clicking headers allows sort by any column
- Filtering: by status (submitted / evaluating / awarded / rejected / withdrawn), date range (from–to)

GIVEN the supplier searches for a tender
WHEN they enter text in a search box
THEN the system filters tenders by title (case-insensitive partial match)

### REQ-003-B: Tender Detail View

GIVEN a user clicks on a tender in the list
WHEN the detail page opens
THEN the system displays:
- Tender title and reference number
- Current status (with status badge: gray=submitted, blue=evaluating, green=awarded, red=rejected, orange=withdrawn)
- Submission date and time
- Contract value (EUR)

GIVEN the tender status is **awarded**
WHEN the user views the detail page
THEN additional fields appear:
- Award date
- "Gunningsbesluit" (award decision) download button (PDF)
- Contractual terms reference
- Next milestone date (contract execution, if known)

GIVEN the tender status is **rejected**
WHEN the user views the detail page
THEN additional fields appear:
- Rejection date
- **Rejection reason** (mandatory per Aanbestedingswet 2012 art. 2.130): free text displayed in full
- Appeal deadline (format: "U kunt tot en met [date] bezwaar maken tegen deze afwijzing.")
- "Download beoordelingsverslag (geanonimiseerd)" button (PDF)
- Link to municipal complaint procedure: "Meer informatie over bezwaar indienen"

GIVEN the tender status is **under_evaluation**
WHEN the user views the detail page
THEN:
- Estimated decision date is displayed (if known from case plannedEndDate)
- Message: "Uw inschrijving wordt beoordeeld. Wij stellen u uiterlijk op [date] op de hoogte van de uitkomst."

### REQ-003-C: Evaluation Report Download (Anonymized)

GIVEN a tender has been awarded OR rejected
WHEN the user clicks "Download beoordelingsverslag"
THEN the system:
- Validates the PDF exists and is marked as anonymized
- Serves the PDF with Content-Disposition: attachment
- Logs the download event for audit trail

The evaluation report MUST be anonymized per Aanbestedingswet 2012 art. 2.130, meaning:
- No other suppliers' names or company identifiers
- Other bidders referred to as "Indiener 2", "Indiener 3", etc.
- All commercially sensitive data (pricing, technical details from other bidders) redacted

---

## REQ-004: Invoice Tracking with Payment Forecast

**Purpose**: Display all invoices with status, expected payment date (calculated from mandate routing), age analysis, and dispute management.

### REQ-004-A: Invoice List and Filters

GIVEN a user with finance or admin role opens the Invoices tab
WHEN the page loads
THEN the system displays:
- List of all invoices submitted by this supplier (via SupplierInvoice entities)
- Columns: Invoice Number, Invoice Date, Amount (EUR), Status, Expected Payment Date, Days Overdue (if applicable)
- Status badges: gray=received, blue=under_review, green=approved, yellow=disputed, red=rejected, green_checkmark=paid
- Sorting: by invoice date (newest first); clickable headers allow re-sort
- Filtering: by status, date range, amount range

WHEN filtering by status
THEN only invoices matching the selected status(es) are shown (multi-select allowed)

### REQ-004-B: Invoice Detail and Expected Payment Date

GIVEN a user clicks on an invoice
WHEN the detail page opens
THEN the system displays:
- Invoice number, date, amount (EUR), VAT amount (EUR)
- Current status with human-readable label
- Invoice due date (contractual term)
- **Expected Payment Date** (calculated as: invoice date + mandate routing delay + payment term days)

EXAMPLE: Invoice 2026-0518 from Jansen & Co
- Invoice Date: 2026-05-01
- Payment terms: 30 days
- Mandate routing delay: 5 days (administrative processing)
- Expected Payment Date: 2026-05-01 + 5 days + 30 days = **2026-06-05**

GIVEN the invoice status is **approved**
WHEN the user views the detail page
THEN the expected payment date is prominently displayed in a green box with icon.

GIVEN the invoice status is **paid**
WHEN the user views the detail page
THEN:
- Actual Payment Date is shown
- Green checkmark and "Betaald op [date]" message
- Difference from expected date (if > 0: "betaald [n] dagen eerder/later dan verwacht")

GIVEN the invoice status is **under_review**
WHEN the user views the detail page
THEN:
- Message: "Uw factuur wordt gecontroleerd. Verwachte betaaldatum volgt na goedkeuring."
- No expected payment date displayed (marked as TBD)

GIVEN the invoice status is **disputed**
WHEN the user views the detail page
THEN:
- Dispute reason is displayed
- "Reactie geven" button (adds a message to the case conversation)
- Reference to associated message/case for follow-up

### REQ-004-C: Age Analysis Dashboard

GIVEN the Invoices tab is open
WHEN the user scrolls to the "Ouderdomsanalyse" (age analysis) section
THEN a summary bar is displayed showing:
- Count and total amount in each bucket:
  - **0–30 days overdue**: [count] invoices, €[amount]
  - **30–60 days overdue**: [count] invoices, €[amount]
  - **60–90 days overdue**: [count] invoices, €[amount]
  - **90+ days overdue**: [count] invoices, €[amount]
- Visual: stacked bar chart with color coding (yellow → orange → red → dark red)
- Clicking a bucket filters the invoice list to show only invoices in that age range

GIVEN an invoice is more than 90 days overdue
WHEN the dashboard loads
THEN:
- The invoice is flagged with a red badge in the list
- An alert email is sent to the supplier's primary contact: "Factuur [number] is nu meer dan 90 dagen openstaand."

### REQ-004-D: Dispute Management

GIVEN an invoice displays a dispute (status = disputed)
WHEN the user clicks "Reactie geven" or views the Messages tab for this case
THEN they can:
- View the dispute reason (free text from the municipality)
- Send a message/response to the case handler
- Provide additional information or documentation
- Request follow-up status

---

## REQ-005: Contract Management with Renewal Warnings

**Purpose**: Display active contracts with renewal tracking and expiration warnings.

### REQ-005-A: Contract List with Expiration Status

GIVEN a user with contracts or admin role opens the Contracts tab
WHEN the page loads
THEN the system displays:
- List of all active contracts (via SupplierContract entities with endDate >= today)
- Columns: Contract Number, Subject, Start Date, End Date, Value (EUR), Account Manager, Renewal Option
- Contracts are sorted by end date (nearest expiry first)

GIVEN a contract has `renewalWarning` = true (within 90 days of expiry)
WHEN the list is displayed
THEN the contract row shows:
- Orange warning badge: "Vervalt over [n] dagen" (e.g., "Vervalt over 39 dagen")
- Highlighted row background (light orange)

GIVEN a contract has `renewalWarning` = false (> 90 days until expiry)
WHEN the list is displayed
THEN the contract row shows normally with no warning.

### REQ-005-B: Contract Detail View

GIVEN a user clicks on a contract in the list
WHEN the detail page opens
THEN the system displays:
- Contract number and title
- Start date and end date
- Contract value (EUR)
- Account manager name and email (for questions about renewal)
- Renewal option type:
  - "Automatische vernieuwing" → next end date after renewal
  - "Vernieuwing op aanvraag" → user must request renewal before expiry
  - "Geen vernieuwing" → contract ends on specified date

GIVEN the contract renewal option is "Vernieuwing op aanvraag"
AND the contract is within 90 days of expiry
WHEN the user views the detail page
THEN a button "Verlenging aanvragen" is displayed prominently.

### REQ-005-C: Contract Renewal Request

GIVEN a user with contracts or admin role clicks "Verlenging aanvragen"
WHEN they click the button
THEN the system:
- Creates a new Procest zaak of type "Leverancier-contractverlenging-verzoek" with:
  - Title: "[Supplier Name] – Contract Renewal Request – [Contract Number]"
  - Description: Contract number, supplier reference, requested renewal term
  - Status: Ontvangen (received)
  - Priority: Normal
- Displays confirmation message: "Uw verlenging aanvraag voor contract [number] is verstuurd naar [gemeente]. Wij nemen contact met u op."
- Logs the request in the contract's timeline
- Sends email notification to the municipal account manager with the renewal request

GIVEN the renewal request is submitted
WHEN the contract detail page is refreshed
THEN the button changes to: "Verlenging aangevraagd op [date]" (disabled/read-only).

---

## REQ-006: Secure Per-Case Messaging

**Purpose**: Enable suppliers to send questions to case handlers and receive responses with full audit trail.

### REQ-006-A: Message Composition Interface

GIVEN a user is viewing a case detail (tender, contract, or invoice)
WHEN they scroll to the Messages section
THEN:
- A text box appears: "Stel hier uw vraag..."
- Optional: file upload for attachments (max 5 files, max 10 MB each)
- Send button: "Verzend bericht"

WHEN the user types a message and clicks "Verzend bericht"
THEN the system:
- Creates a SupplierMessage record with:
  - `caseRef` = the case being viewed
  - `supplierRef` = logged-in supplier
  - `direction` = inbound
  - `subject` = auto-generated from first line of message (if > 50 chars, truncate + "...")
  - `body` = full message text
  - `attachmentRefs[]` = uploaded file IDs (if any)
  - `sentBy` = current SupplierUser ID
  - `sentAt` = now
- Displays success message: "Uw bericht is verstuurd."
- Clears the text box
- Adds the message to the conversation history below

### REQ-006-B: Message Routing to Case Handler

GIVEN a SupplierMessage is created
WHEN the system processes it
THEN:
- A notification is sent to the case handler's inbox in Procest (if assigned; else to the appropriate team)
- Email notification is sent to the handler's email address with:
  - Subject: "Nieuw bericht van leverancier [supplier name] – [zaak ref]"
  - Body preview: first 100 chars of message
  - Link to case in Procest with message visible
  - Call-to-action: "Beantwoord bericht"

### REQ-006-C: Handler Response and Supplier Notification

GIVEN a case handler receives the notification
WHEN they read and respond to the message in Procest
THEN the system:
- Creates a SupplierMessage record with `direction` = outbound (handler response)
- Sends email notification to the supplier's primary contact:
  - Subject: "Antwoord op uw bericht – [zaak ref] [supplier name]"
  - Body: handler's response text
  - Link to portal to view full conversation
  - Indication: "Beantwoord op [date] door [handler name]"
- Message appears in the portal's message history (newer messages at bottom, conversation thread style)

### REQ-006-D: Message History and Audit Trail

GIVEN the user opens the Messages section
WHEN the page loads
THEN the system displays:
- Chronological conversation thread (newest at bottom, oldest at top)
- Each message shows: sender name, timestamp, message text, attachments (if any)
- Inbound messages (from supplier) in light background; outbound messages (from handler) in white background
- Attachment links (downloadable)

GIVEN the supplier account is archived or suspended
WHEN an administrator reviews the case history
THEN all SupplierMessage records remain visible for audit and compliance purposes (immutable, read-only).

---

## REQ-007: Self-Service Master Data Mutations

**Purpose**: Allow suppliers to update address, contact person, IBAN, and other master data with appropriate approval workflows.

### REQ-007-A: Address and Contact Person Updates (Auto-Applied)

GIVEN a user with admin role navigates to "Mijn Gegevens" (My Details)
WHEN they scroll to "Bedrijfsgegevens"
THEN they see:
- Current address (read-only display)
- Current contact person (read-only display)
- Buttons: "Wijzigen" (edit address), "Wijzigen" (edit contact person)

WHEN they click "Wijzigen" for address
THEN a form appears with fields:
- Street address, number, postal code, city
- Validation: postal code format (1234 AB), city selection from Dutch city list
- Submit button: "Wijziging opslaan"

WHEN they click "Wijziging opslaan"
THEN the system:
- Validates all required fields are populated
- Creates a SupplierMasterDataMutation audit record with type = "address_change"
- **Immediately applies the change** to the Supplier record (auto-approved for address)
- Displays confirmation: "Adreswijziging is opgeslagen en direct actief."
- Sends email notification to primary contact: "Uw bedrijfsadres is gewijzigd naar [new address]."
- Logs audit entry: "Address updated from [old] to [new] by [user] at [timestamp]"

Same workflow for contact person (auto-applied).

### REQ-007-B: IBAN Update (Requires Re-Auth and 4-Eyes Approval)

GIVEN a user clicks "Wijzigen" for IBAN
WHEN the form appears
THEN:
- Message: "IBAN-wijziging vereist extra verificatie voor uw veiligheid."
- Step 1: "Verificatie bankrekening"
  - Form: Current IBAN (masked: IBAN **** **** 1234)
  - Button: "Verifieer huidige rekening"
  - This triggers a test transfer or known-at-bank verification via OpenConnector
- Step 2: After verification passes
  - Form: New IBAN field
  - Validation: IBAN format (NL + 2 check digits + 4 bank code + 10 account number)
  - Button: "Volgende"
- Step 3: Confirmation
  - Display: "Uw bankrekening wijzigt van [old IBAN masked] naar [new IBAN masked]"
  - Message: "Deze wijziging wordt gereviewd door twee medewerkers van de gemeente en kan 1–2 werkdagen duren."
  - Button: "Wijziging aanvragen"

WHEN the supplier submits the IBAN change request
THEN the system:
- Creates a Procest zaak of type "Leverancier-IBAN-wijziging" with:
  - Title: "[Supplier Name] – IBAN Change Request"
  - Payload: Old IBAN (masked), new IBAN (masked), supplier reference, timestamp
  - Workflow: "4-eyes" approval (2 municipal staff must approve before change is effective)
  - Status: Open
- Displays message: "Uw aanvraag is ingediend. We nemen contact met u op als de wijziging is verwerkt."
- Sends email to municipal finance team with the 4-eyes approval task

GIVEN both reviewers approve the IBAN change
WHEN the approval workflow is complete in Procest
THEN:
- The Supplier record's IBAN field is updated
- SupplierMasterDataMutation audit record is finalized
- Email is sent to the supplier: "Uw IBAN-wijziging is verwerkt en actief sinds [date]."

GIVEN one reviewer rejects the IBAN change
WHEN the rejection is recorded
THEN:
- Email is sent to the supplier: "Uw IBAN-wijziging is afgewezen. Reden: [rejection reason]."
- The old IBAN remains active
- The supplier can submit a new request after addressing the issue

### REQ-007-C: SBI Codes and Accreditations (Submitted for Verification)

GIVEN a user clicks "Wijzigen" for SBI codes or accreditations
WHEN the form appears
THEN:
- Read-only display of current SBI codes (e.g., "41100 – Verbouw van gebouwen")
- Checkbox list of available accreditations with current statuses
- Instructions: "Wijzigingen van SBI-codes en certificaten moeten door ons team worden geverifieerd."
- Form to add new accreditation: dropdown of known types (ISO-9001, ISO-27001, VCA-VNA, etc.), or free-text field for others
- Attach proof of accreditation (PDF scan, certificate copy)
- Button: "Indienen voor verificatie"

WHEN the supplier submits the verification request
THEN the system:
- Creates a Procest zaak of type "Leverancier-accreditatie-verificatie"
- Stores the proposed changes and attachments in the case
- Status: Pending verification
- Email sent to municipal procurement team with the verification task
- Portal shows: "Uw accreditatiewijziging is ingediend. Wij controleren dit en informeren u van de uitkomst."

GIVEN the municipal team verifies the submission
WHEN they approve
THEN:
- The Supplier record's `sbiCodes` and `accreditations` are updated
- Email sent to supplier: "Uw SBI-codes en certificaten zijn geverifieerd en bijgewerkt."

GIVEN verification fails (e.g., expired certificate)
WHEN rejection is recorded
THEN:
- Email sent to supplier: "Uw indiening kon niet worden goedgekeurd: [reason]. Dien opnieuw in als dit is opgelost."

---

## REQ-008: KPI Dashboard with 12-Month Trends

**Purpose**: Display supplier performance metrics with historical trends and export capability.

### REQ-008-A: KPI Summary Cards

GIVEN a user with admin or finance role opens the KPI Dashboard
WHEN the page loads
THEN the system displays four metric cards:

1. **Gemiddelde betaaltermijn (Average Payment Days)**
   - Metric value: number of days (e.g., "28 dagen")
   - Comparison to municipal average: "Gemeentelijk gemiddelde: 30 dagen" (with ↓ if better, ↑ if worse)
   - 12-month trend line chart below

2. **Tijdig betaald percentage (On-Time Payment %)**
   - Metric value: percentage (e.g., "94%")
   - Trend: "Vorige maand: 92%" (with direction indicator)
   - 12-month trend line chart

3. **Geschillenpercentage (Dispute Rate)**
   - Metric value: percentage of invoices with disputes (e.g., "2.1%")
   - Absolute count: "[n] geschillen van [total] facturen in 12 maanden"
   - 12-month trend bar chart

4. **Contract Compliance Score**
   - Metric value: 0–100 score (e.g., "87/100")
   - Explanation: Based on on-time deliveries, SLA compliance, compliance with contract terms
   - 12-month trend line chart

### REQ-008-B: Trend Visualization

GIVEN the KPI Dashboard is displayed
WHEN the user views each metric card
THEN each card includes:
- A chart (line for trends, bar for distributions) showing 12 data points (one per month)
- X-axis: month labels (Jan, Feb, Mar, ..., Dec)
- Y-axis: metric value (days, %, or score)
- Tooltip on hover: "May 2026: 28 days" (or equivalent for other metrics)
- Legend (if applicable): "Own" vs. "Municipal Average"

### REQ-008-C: Data Export

GIVEN the user is viewing the KPI Dashboard
WHEN they scroll to the bottom
THEN an "Export to CSV" button is visible.

WHEN they click "Export to CSV"
THEN the system:
- Generates a CSV file with columns: Date, Metric, Value, Benchmark (municipal average), Notes
- Filename: `[supplier-name]_KPI_[YYYY-MM-DD].csv`
- Downloads the file to the user's computer
- Logs the export event for audit trail

The CSV includes:
- 12 rows per metric (1 row per month for last 12 months)
- All four metrics (avg payment days, on-time %, dispute rate, compliance score)
- Total 48 rows of data (4 metrics × 12 months) plus header row
- Values rounded to 1 decimal place
- Dates in ISO format (YYYY-MM)

### REQ-008-D: Aggregation Logic and Schedule

GIVEN the KPI aggregation job runs nightly at 02:00 UTC
WHEN the job executes
THEN for each supplier:
- Calculate average payment days: (sum of actual_payment_date - invoice_date) / count of paid invoices in month
  - Exclude invoices with disputes or pending status
  - Exclude payments made with extreme outliers (>200 days) which may indicate late disputes
- Calculate on-time payment %: (count of invoices paid by dueDate) / (total invoices in month) × 100
- Calculate dispute rate: (count of disputed invoices) / (total invoices) × 100
- Calculate compliance score: weighted average of sub-scores (on-time delivery, SLA compliance from contracts, terms adherence)
- Store SupplierKPI records for the previous month with period = "YYYY-MM"
- Benchmark: query all suppliers' metrics for the same period to calculate municipal average; store in `benchmark` field

GIVEN a supplier has < 3 invoices in a month
WHEN the aggregation runs
THEN:
- Mark the month's metric as "Insufficient data" in the portal
- Do not display the month in the trend chart (skip it)
- Show message: "Onvoldoende gegevens deze maand (minder dan 3 facturen)"

---

## REQ-009: Portal Accessibility and Security

**Purpose**: Ensure portal meets WCAG 2.1 AA and AVG compliance.

### REQ-009-A: Accessibility (WCAG 2.1 AA)

All portal pages MUST:
- Support keyboard navigation (Tab, Shift+Tab, Enter, Escape)
- Include ARIA labels on all interactive elements
- Maintain color contrast ratio ≥ 4.5:1 for normal text, ≥ 3:1 for large text
- Be tested with screen readers (NVDA, JAWS, VoiceOver)
- Support text zoom up to 200% without horizontal scrolling

### REQ-009-B: Session Security

- Session TTL: 2 hours with automatic refresh 15 minutes before expiry
- Re-authentication required for:
  - Invoice viewing (financial data)
  - IBAN/payment term updates
  - Team member invitation (admin actions)
- Secure session cookies: HttpOnly, Secure, SameSite=Strict
- CSRF protection on all POST/PUT/DELETE endpoints

### REQ-009-C: Data Privacy (AVG Art. 25, 32)

- Supplier scoping enforced at every API layer (no cross-supplier data leakage)
- All mutations logged to audit trail with user, timestamp, old/new values
- Sensitive fields (IBAN) masked in displays and logs
- Data retention: supplier data retained for 7 years (per Dutch tax law); messages for 5 years
- Encryption in transit (HTTPS/TLS 1.3+) and at rest (AES-256 for PII)

---
