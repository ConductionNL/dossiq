# Tasks: leverancier-zaakportaal

Implementation tasks for the supplier portal, covering authentication, frontend, backend services, integrations, and testing.

---

## 1. Authentication & Authorization

### Task 1.1: eHerkenning Login Integration

**Spec ref**: REQ-001
**Files**: 
- `lib/Service/SupplierAuthService.php` (new)
- `lib/Controller/AuthController.php` (new)
- `src/pages/auth/login.vue` (new)
- `src/pages/auth/callback.vue` (new)
- `src/middleware/supplierAuth.ts` (new)

**Acceptance criteria**:
- GIVEN a supplier visits login page WHEN clicks eHerkenning button THEN redirects to OpenConnector broker with correct scopes
- GIVEN eHerkenning returns code WHEN exchanged THEN SupplierUser created/updated and session issued with 2-hour TTL
- GIVEN KvK claim invalid WHEN validated THEN error page displayed with no session created
- GIVEN session nearing expiry WHEN user acts THEN automatic refresh occurs (transparent to user)

- [ ] Implement `SupplierAuthService.authenticateViaEHerkenning(code)` → validates code, exchanges for token, extracts KvK claim
- [ ] Implement `SupplierAuthService.validateKvKClaim(kvkNumber)` → looks up Supplier in OpenRegister, checks status
- [ ] Implement `SupplierAuthService.createOrLinkSupplierUser(supplierRef, eherkenningClaim)` → creates/updates SupplierUser, sets role defaults
- [ ] Implement `SupplierAuthService.issueSessionToken(supplierUserId)` → generates JWT/session with 2-hour TTL
- [ ] Create `AuthController` endpoints: GET /auth/eherkenning-login, GET /auth/callback, POST /auth/logout, POST /auth/refresh
- [ ] Build login page Vue component: eHerkenning button, error display, loading state
- [ ] Build callback page: validates token, stores session, redirects to dashboard
- [ ] Implement session middleware: validates token on each protected route, triggers re-auth if expired
- [ ] Test with real eHerkenning sandbox (coordinate with OpenConnector team)
- [ ] Test session refresh at 1 hour 45 minutes mark
- [ ] Test logout and session invalidation

### Task 1.2: Role-Based Access Control (RBAC)

**Spec ref**: REQ-002
**Files**:
- `lib/Service/SupplierUserManagementService.php` (new)
- `lib/Controller/UserManagementController.php` (new)
- `src/components/DashboardTabs.vue` (new)
- `src/middleware/roleGuard.ts` (new)

**Acceptance criteria**:
- GIVEN user with finance role WHEN opens Invoices tab THEN displayed; Tenders tab hidden
- GIVEN admin user WHEN invites team member THEN email sent with activation link, activates on eHerkenning login
- GIVEN access revoked WHEN sessions refresh THEN user logged out, cannot re-access
- Test matrix: all role combinations with correct tab visibility

- [ ] Implement `SupplierUserManagementService.inviteSupplierUser(supplierRef, email, role)` → creates SupplierUser with status=invited, generates token, sends email
- [ ] Implement activation endpoint: GET /users/activate?token={token} → validates token, links eHerkenning user, sets status=active
- [ ] Implement role update endpoint: POST /users/{userId}/role → updates role, validates admin permission, logs change
- [ ] Implement revoke access endpoint: DELETE /users/{userId} → sets status=revoked, invalidates all sessions for user
- [ ] Create DashboardTabs component: displays tabs based on user role, hides unauthorized tabs
- [ ] Implement roleGuard middleware: protects routes by role (e.g., @roleGuard('finance') protects invoice routes)
- [ ] Build Team management page: list members, edit roles, revoke access, invite new members (admin only)
- [ ] Test all role + tab combinations (4 roles × 7 tabs = 28 test cases)
- [ ] Test invitation email delivery and token expiry (7 days)
- [ ] Test session invalidation after role revocation

---

## 2. Frontend Components

### Task 2.1: Dashboard and Layout

**Spec ref**: REQ-003, REQ-004, REQ-005, REQ-008
**Files**:
- `src/pages/dashboard.vue` (new)
- `src/layouts/PortalLayout.vue` (new)
- `src/components/DashboardSummary.vue` (new)
- `src/components/NavBar.vue` (new)

**Acceptance criteria**:
- GIVEN dashboard loads WHEN authenticated THEN summary cards displayed: open tenders, unpaid invoices, expiring contracts, KPI headline
- GIVEN role-based nav WHEN user views nav THEN only relevant tabs visible
- GIVEN logout clicked WHEN session destroyed THEN redirected to login page
- Portal matches NL Design System styling and accessibility standards (WCAG 2.1 AA)

- [ ] Create PortalLayout component: header (logo, user menu, nav), main content area, footer
- [ ] Build DashboardSummary component: 4 cards (tenders, invoices, contracts, KPI) with counts and status badges
- [ ] Implement NavBar: dynamically shows tabs based on user role
- [ ] Add user profile menu: shows name, role, "Mijn Gegevens" link, Logout button
- [ ] Implement responsive design for mobile/tablet (CSS Grid breakpoints)
- [ ] Add loading states and error boundaries
- [ ] Use NL Design System components (buttons, cards, tables, modals)
- [ ] Test with screen reader (NVDA) for accessibility
- [ ] Test color contrast (minimum 4.5:1 for normal text)
- [ ] Test keyboard navigation (Tab through all interactive elements)

### Task 2.2: Tender List and Detail View

**Spec ref**: REQ-003
**Files**:
- `src/pages/tenders/index.vue` (new)
- `src/pages/tenders/_id.vue` (new)
- `src/components/TenderList.vue` (new)
- `src/components/TenderDetail.vue` (new)
- `src/components/TenderStatusBadge.vue` (new)

**Acceptance criteria**:
- GIVEN tenders tab opens WHEN data loads THEN list displayed with status, title, date, value
- GIVEN tender clicked WHEN detail page opens THEN status-specific fields shown (award date if awarded, rejection reason if rejected)
- GIVEN rejected tender WHEN viewed THEN anonymized evaluation report download available, appeal deadline shown
- Filtering and sorting work correctly

- [ ] Implement TenderList component: table with sorting (click headers), filtering (status, date range, search)
- [ ] Fetch `GET /api/supplier-portal/tenders` and bind to table
- [ ] Create status badges: submitted (gray), evaluating (blue), awarded (green), rejected (red), withdrawn (orange)
- [ ] Build TenderDetail page: show all tender fields, conditionally display award/rejection info
- [ ] Implement conditional rendering: award date + letter download if awarded; rejection reason + appeal deadline + evaluation report download if rejected
- [ ] Add document download buttons: handle PDF serving with correct headers (Content-Disposition: attachment)
- [ ] Implement data caching (cache tender list for 5 minutes)
- [ ] Test with 10+ tenders in list; verify sorting/filtering performance
- [ ] Test PDF download from evaluation report
- [ ] Verify appeal deadline formatting and accuracy

### Task 2.3: Invoice List, Detail, and Age Analysis

**Spec ref**: REQ-004
**Files**:
- `src/pages/invoices/index.vue` (new)
- `src/pages/invoices/_id.vue` (new)
- `src/components/InvoiceList.vue` (new)
- `src/components/InvoiceDetail.vue` (new)
- `src/components/AgeAnalysisBar.vue` (new)

**Acceptance criteria**:
- GIVEN invoices tab opens WHEN data loads THEN list displayed with columns: number, date, amount, status, expected payment date
- GIVEN approved invoice WHEN viewed THEN expected payment date displayed in green box
- GIVEN age analysis section WHEN viewed THEN stacked bar shows overdue buckets (0–30, 30–60, 60–90, 90+)
- GIVEN 90+ days overdue WHEN alert threshold reached THEN email sent to supplier
- Filtering by status, date range, amount range works

- [ ] Implement InvoiceList component: table with sorting, filtering, status badges
- [ ] Fetch `GET /api/supplier-portal/invoices` with support for filters (?status=paid&dateFrom=2026-01-01&dateTo=2026-05-31)
- [ ] Create status badges: received (gray), under_review (blue), approved (green), disputed (yellow), rejected (red), paid (green checkmark)
- [ ] Build InvoiceDetail page: show invoice data, status, expected payment date (in green box if approved)
- [ ] Implement AgeAnalysisBar component: stacked horizontal bar chart with buckets (0–30, 30–60, 60–90, 90+)
- [ ] Add bucket filtering: clicking a bucket filters invoice list to show only invoices in that age range
- [ ] Implement dispute management: if status=disputed, show "Reactie geven" button → opens message composer
- [ ] Add nightly job to flag 90+ day overdue invoices and send alert emails
- [ ] Test payment date calculation: invoice date + mandate delay + payment terms = forecast
- [ ] Test age analysis buckets with edge cases (invoices exactly on bucket boundaries)
- [ ] Test email alert for 90+ day overdue

### Task 2.4: Contract List, Detail, and Renewal Workflow

**Spec ref**: REQ-005
**Files**:
- `src/pages/contracts/index.vue` (new)
- `src/pages/contracts/_id.vue` (new)
- `src/components/ContractList.vue` (new)
- `src/components/ContractDetail.vue` (new)
- `src/components/RenewalRequestModal.vue` (new)

**Acceptance criteria**:
- GIVEN contracts tab opens WHEN data loads THEN list displayed, sorted by end date (nearest first)
- GIVEN contract within 90 days WHEN viewed THEN orange warning badge shown ("Vervalt over [n] dagen")
- GIVEN renewal request button clicked WHEN submitted THEN Procest zaak created, email sent to account manager, portal confirms submission
- Contract renewal option types (auto, manual, none) displayed correctly

- [ ] Implement ContractList component: table with columns (number, subject, start, end, value, account manager, renewal option)
- [ ] Fetch `GET /api/supplier-portal/contracts` and bind to table
- [ ] Add sorting: default by end date (nearest first), clicking headers re-sorts
- [ ] Create warning badge: if daysUntilExpiry < 90, show orange badge "Vervalt over [n] dagen"
- [ ] Build ContractDetail page: display all contract fields, conditionally show renewal option details
- [ ] Implement "Verlenging aanvragen" button: only visible if renewal option = manual_request AND within 90 days
- [ ] Create RenewalRequestModal: confirmation, sends POST /api/supplier-portal/contracts/{id}/request-renewal
- [ ] Backend handler: creates Procest zaak, sends email to account manager
- [ ] Display confirmation: "Verlenging aangevraagd op [date]" (button becomes disabled)
- [ ] Test renewal request creation and Procest integration
- [ ] Test with contracts at exact 90-day boundary
- [ ] Test all three renewal option types (auto, manual, none)

### Task 2.5: Messaging Interface

**Spec ref**: REQ-006
**Files**:
- `src/pages/messages.vue` (new)
- `src/components/MessageComposer.vue` (new)
- `src/components/MessageThread.vue` (new)
- `src/components/MessageBubble.vue` (new)

**Acceptance criteria**:
- GIVEN message section on case detail WHEN user types THEN text box accepts input and attachments
- GIVEN message sent WHEN submitted THEN confirmation shown, message appears in thread, handler receives notification
- GIVEN handler responds WHEN message sent THEN supplier receives email, response appears in portal thread
- Message history displayed as conversation thread with timestamps, sender info

- [ ] Implement MessageComposer component: text area (required), optional file uploads (max 5, max 10 MB each)
- [ ] Add file upload validation: check type (PDF, DOC, XLS, JPG, PNG, etc.), size
- [ ] Implement message submission: POST /api/supplier-portal/messages with {caseId, body, attachmentRefs[]}
- [ ] Build MessageThread component: displays messages chronologically, inbound (light bg) vs outbound (white bg)
- [ ] Create MessageBubble component: shows sender name, timestamp, message text, attachments (downloadable links)
- [ ] Implement email notification trigger: on message creation, POST event to notification queue
- [ ] Implement handler response display: when handler adds response in Procest, fetch updated messages and display in thread
- [ ] Add soft loading: when composer clears, show "Bericht verstuurd" success message
- [ ] Test message sending with attachments
- [ ] Test email notifications to handler and supplier
- [ ] Test long message text and multiple attachments handling

### Task 2.6: Master Data (Mijn Gegevens)

**Spec ref**: REQ-007
**Files**:
- `src/pages/profile/index.vue` (new)
- `src/pages/profile/address.vue` (new)
- `src/pages/profile/iban.vue` (new)
- `src/pages/profile/contact.vue` (new)
- `src/components/ProfileForm.vue` (new)
- `src/components/IBANVerificationFlow.vue` (new)

**Acceptance criteria**:
- GIVEN profile page opens WHEN authenticated WHEN address/contact info displayed read-only with "Wijzigen" buttons
- GIVEN address edit WHEN submitted THEN immediately applied, confirmation shown, email sent
- GIVEN IBAN edit WHEN submitted THEN verification step required, 4-eyes Procest task created, confirmation pending
- GIVEN SBI/accreditation edit WHEN submitted THEN submitted for verification, email sent to procurement team

- [ ] Build ProfileView page: sections for address, contact person, IBAN, SBI codes, accreditations
- [ ] Create AddressForm: fields (street, number, postal code, city), validation, "Wijzigen" button
- [ ] Create ContactPersonForm: fields (name, email, phone), validation
- [ ] On address/contact submit: POST /api/supplier-portal/profile/{field}, immediately apply change, show confirmation
- [ ] Create IBANVerificationFlow component: Step 1 (verify current account), Step 2 (enter new IBAN), Step 3 (confirmation)
- [ ] Implement Step 1: calls verification endpoint, shows result (pass/fail)
- [ ] Implement Step 2: IBAN field with format validation (NL + check digits + account)
- [ ] Implement Step 3: displays masked old/new IBANs, warning about 4-eyes approval, "Wijziging aanvragen" button
- [ ] On IBAN submit: POST /api/supplier-portal/profile/iban, creates Procest zaak, shows "Wijziging ingediend" message
- [ ] Create SBIAccreditationForm: checkboxes for known types, upload proof, "Indienen voor verificatie"
- [ ] Test address change (immediate application)
- [ ] Test IBAN change (4-eyes Procest workflow)
- [ ] Test accreditation submission
- [ ] Verify email notifications on each update

### Task 2.7: KPI Dashboard

**Spec ref**: REQ-008
**Files**:
- `src/pages/kpi.vue` (new)
- `src/components/KPICard.vue` (new)
- `src/components/TrendChart.vue` (new)
- `src/components/MetricCard.vue` (new)

**Acceptance criteria**:
- GIVEN KPI page opens WHEN data loads THEN 4 cards displayed: avg payment days, on-time %, dispute rate, compliance score
- GIVEN card viewed WHEN trends visible THEN 12-month line/bar chart shown with month labels and values
- GIVEN export button clicked WHEN CSV generated THEN file downloads with 48 rows (4 metrics × 12 months) + header
- GIVEN insufficient data (< 3 invoices month) WHEN displayed THEN marked as "Insufficient data", skipped from chart

- [ ] Implement KPICard component: metric title, current value, comparison to benchmark, trend chart
- [ ] Fetch `GET /api/supplier-portal/kpis` for current snapshot
- [ ] Fetch `GET /api/supplier-portal/kpis/trends` for 12-month data
- [ ] Create TrendChart component: uses Chart.js or similar; line chart for payment days and on-time %, bar for dispute rate
- [ ] Implement X-axis: month labels (Jan, Feb, Mar, ..., Dec)
- [ ] Implement Y-axis: metric-specific (days, %, score out of 100)
- [ ] Implement tooltip on hover: "May 2026: 28 days"
- [ ] Implement CSV export: GET /api/supplier-portal/kpis/export, trigger download
- [ ] Test with 12 full months of data
- [ ] Test with sparse data (< 3 invoices some months)
- [ ] Test CSV format and content (48 rows + header)
- [ ] Verify benchmark comparison (own vs. municipal average)

---

## 3. Backend Services

### Task 3.1: Supplier Scope Service

**Spec ref**: REQ-001, REQ-003, REQ-004, REQ-005
**Files**:
- `lib/Service/SupplierScopeService.php` (new)
- `lib/Service/CaseSupplierLookup.php` (new)

**Acceptance criteria**:
- GIVEN supplier A requests cases WHEN scoped THEN only supplier A's cases returned
- GIVEN supplier B requests supplier A's case WHEN validated THEN access denied with 403 error
- GIVEN supplier has no cases WHEN scoped THEN empty result set returned
- All API endpoints use scope validation (no cross-supplier data leakage)

- [ ] Implement `SupplierScopeService.getCurrentSupplier()` → returns current logged-in supplier from session
- [ ] Implement `SupplierScopeService.getSupplierCases(supplierRef)` → queries OpenRegister for all cases linked to supplier
- [ ] Implement `SupplierScopeService.validateSupplierAccess(caseId, supplierRef)` → checks if supplier can access case
- [ ] Implement `CaseSupplierLookup` service: finds SupplierTender/Contract/Invoice records for a case
- [ ] Test scope validation: supplier A cannot access supplier B's cases
- [ ] Test with edge cases: suspended suppliers, newly onboarded suppliers
- [ ] Verify all API controllers use scope validation before returning data

### Task 3.2: Tender Visibility Service

**Spec ref**: REQ-003
**Files**:
- `lib/Service/TenderVisibilityService.php` (new)
- `lib/Controller/TenderController.php` (new)

**Acceptance criteria**:
- GIVEN tender list requested WHEN fetched THEN SupplierTender entities returned with status, dates, values
- GIVEN rejected tender WHEN viewed THEN rejection reason, appeal deadline, anonymized report provided
- GIVEN awarded tender WHEN viewed THEN award date, award letter download available
- GIVEN invalid tender ID WHEN requested THEN 404 or 403 returned

- [ ] Implement `TenderVisibilityService.getTenderStatus(tenderId)` → fetches SupplierTender, includes derived fields (daysUntilAppealDeadline, etc.)
- [ ] Implement `TenderVisibilityService.getEvaluationReport(tenderId)` → serves anonymized PDF
- [ ] Implement `TenderVisibilityService.getAwardLetter(tenderId)` → serves award letter PDF
- [ ] Implement `TenderVisibilityService.getAppealDeadline(tenderId)` → calculates appeal deadline (20 days from rejection date)
- [ ] Implement `TenderController` endpoints: GET /tenders (list), GET /tenders/{id} (detail), GET /tenders/{id}/evaluation-report (download)
- [ ] Add scope validation to all endpoints via middleware
- [ ] Test with various tender states (submitted, evaluating, awarded, rejected, withdrawn)
- [ ] Test PDF downloads and content verification
- [ ] Test appeal deadline calculation

### Task 3.3: Invoice Payment Forecast Service

**Spec ref**: REQ-004
**Files**:
- `lib/Service/InvoicePaymentForecastService.php` (new)
- `lib/Controller/InvoiceController.php` (new)

**Acceptance criteria**:
- GIVEN invoice approved WHEN fetched THEN expectedPaymentDate calculated (invoice date + mandate delay + payment terms)
- GIVEN invoice payment applied WHEN viewed THEN actualPaymentDate shown, difference from forecast indicated
- GIVEN age analysis requested WHEN fetched THEN correct bucket counts and totals (0–30, 30–60, 60–90, 90+)
- GIVEN invoice 90+ days overdue WHEN threshold reached THEN alert email sent to supplier

- [ ] Implement `InvoicePaymentForecastService.calculateExpectedPaymentDate(invoiceRef)` → joins with Decidesk mandate routing + payment terms
- [ ] Implement formula: expectedPaymentDate = invoiceDate + mandateRoutingDays + paymentTermsDays
- [ ] Implement `InvoicePaymentForecastService.getAgeAnalysis(supplierRef)` → returns buckets with counts, totals, percentages
- [ ] Implement nightly job: flag invoices 90+ days overdue, send alert emails
- [ ] Implement `InvoiceController` endpoints: GET /invoices (list), GET /invoices/{id} (detail), POST /invoices/{id}/dispute (dispute), GET /invoices/age-analysis
- [ ] Test with real invoice data (coordinated with Decidesk team)
- [ ] Test age calculation at bucket boundaries (exactly 30, 60, 90 days)
- [ ] Test email alert for 90+ day threshold
- [ ] Verify mandate routing integration (coordinate with Decidesk)

### Task 3.4: Contract Renewal Service

**Spec ref**: REQ-005
**Files**:
- `lib/Service/ContractRenewalService.php` (new)
- `lib/Controller/ContractController.php` (new)
- `lib/Jobs/ScanExpiringContractsJob.php` (new)

**Acceptance criteria**:
- GIVEN contract within 90 days of expiry WHEN scanned THEN renewalWarning=true, daysUntilExpiry calculated
- GIVEN renewal request submitted WHEN processed THEN Procest zaak created with correct payload
- GIVEN renewal workflow completed WHEN approved THEN email sent to supplier confirmation
- GIVEN contract expires WHEN date passes THEN follow-up task created

- [ ] Implement `ContractRenewalService.scanExpiringContracts()` → finds contracts with endDate within 90 days, sets renewalWarning=true
- [ ] Implement `ContractRenewalService.flagContractWithinThreshold(contractRef)` → calculates daysUntilExpiry
- [ ] Implement `ContractRenewalService.requestRenewal(contractRef)` → creates Procest zaak of type "Leverancier-contractverlenging-verzoek"
- [ ] Implement `ScanExpiringContractsJob`: nightly job at 03:00 UTC, runs scanExpiringContracts(), sends emails to suppliers with expiring contracts
- [ ] Implement `ContractController` endpoints: GET /contracts (list), GET /contracts/{id} (detail), POST /contracts/{id}/request-renewal
- [ ] Test with contracts at 90-day boundary, < 90 days, > 90 days
- [ ] Test renewal request creation and Procest integration
- [ ] Test email notifications to account managers
- [ ] Verify renewal request workflow in Procest

### Task 3.5: Supplier Master Data Mutation Service

**Spec ref**: REQ-007
**Files**:
- `lib/Service/SupplierMasterDataMutationService.php` (new)
- `lib/Controller/ProfileController.php` (new)
- `lib/Jobs/ProcessMasterDataMutationsJob.php` (new)

**Acceptance criteria**:
- GIVEN address update WHEN submitted THEN immediately applied, confirmation sent
- GIVEN IBAN update WHEN submitted THEN verification required, Procest 4-eyes task created, applied only after approval
- GIVEN SBI/accreditation update WHEN submitted THEN submitted for verification, not auto-applied
- All mutations logged to audit trail with old/new values

- [ ] Implement `SupplierMasterDataMutationService.updateAddress(supplierRef, newAddress)` → applies immediately, logs audit entry
- [ ] Implement `SupplierMasterDataMutationService.updateContactPerson(supplierRef, newContact)` → applies immediately, logs audit
- [ ] Implement `SupplierMasterDataMutationService.requestIBANChange(supplierRef, newIBAN)` → creates Procest zaak for 4-eyes approval, does NOT apply immediately
- [ ] Implement `SupplierMasterDataMutationService.submitForVerification(supplierRef, dataType, attachments)` → creates Procest zaak, stores attachments
- [ ] Implement `ProcessMasterDataMutationsJob`: nightly job to process queued mutations, update Supplier records post-approval
- [ ] Implement `ProfileController` endpoints: GET /profile, POST /profile/address, POST /profile/contact, POST /profile/iban, POST /profile/accreditations
- [ ] Test address/contact updates (immediate application)
- [ ] Test IBAN change workflow (Procest 4-eyes integration)
- [ ] Test accreditation submission
- [ ] Verify audit logging of all mutations
- [ ] Test re-auth requirement for financial mutations (IBAN, invoice viewing)

### Task 3.6: Supplier Message Service

**Spec ref**: REQ-006
**Files**:
- `lib/Service/SupplierMessageService.php` (new)
- `lib/Controller/MessageController.php` (new)
- `lib/Jobs/RouteSupplierMessageJob.php` (new)

**Acceptance criteria**:
- GIVEN message sent WHEN submitted THEN SupplierMessage created, handler notified (inbox + email), conversation logged
- GIVEN handler responds WHEN message sent in Procest THEN outbound SupplierMessage created, supplier notified (email + portal)
- GIVEN message thread viewed WHEN page loads THEN chronological conversation displayed with proper sender info
- All messages immutable post-creation (audit trail)

- [ ] Implement `SupplierMessageService.sendMessage(caseRef, supplierRef, body, attachmentRefs)` → creates SupplierMessage (inbound)
- [ ] Implement `SupplierMessageService.addResponse(messageRef, handlerResponse)` → creates SupplierMessage (outbound)
- [ ] Implement `SupplierMessageService.getConversationHistory(caseRef, supplierRef)` → returns all SupplierMessage records for case, chronological order
- [ ] Implement `RouteSupplierMessageJob`: on message creation, dispatch event to notification queue for handler inbox + email
- [ ] Implement `MessageController` endpoints: GET /messages?caseId=... (list), POST /messages (send), GET /messages/{id} (detail)
- [ ] Test message sending with attachments
- [ ] Test handler response workflow (Procest → portal)
- [ ] Test conversation history display and sorting
- [ ] Test email notifications to both handler and supplier
- [ ] Verify attachment handling (max 5, max 10 MB each)

### Task 3.7: KPI Aggregation Service

**Spec ref**: REQ-008
**Files**:
- `lib/Service/SupplierKPIAggregationService.php` (new)
- `lib/Jobs/AggregateSupplierKPIsJob.php` (new)
- `lib/Controller/KPIController.php` (new)

**Acceptance criteria**:
- GIVEN nightly aggregation runs WHEN executed THEN KPI metrics calculated for prior month: avg payment days, on-time %, dispute rate, compliance score
- GIVEN insufficient data (< 3 invoices) WHEN month processed THEN marked as "Insufficient data", skipped from charts
- GIVEN metrics calculated WHEN compared THEN municipal benchmark included, own vs. average indicated
- GIVEN export requested WHEN generated THEN CSV with 48 rows (4 metrics × 12 months) downloaded

- [ ] Implement `SupplierKPIAggregationService.aggregateKPIs(supplierRef, period)` → calculates all 4 metrics for given month
- [ ] Implement `SupplierKPIAggregationService.calculatePaymentDaysMetric(supplierRef, period)` → avg of (actualPaymentDate - invoiceDate) for paid invoices
- [ ] Implement `SupplierKPIAggregationService.calculateOnTimePercentage(supplierRef, period)` → (paid by dueDate) / total × 100
- [ ] Implement `SupplierKPIAggregationService.calculateDisputeRate(supplierRef, period)` → disputed / total × 100
- [ ] Implement `SupplierKPIAggregationService.calculateComplianceScore(supplierRef, period)` → weighted average of sub-scores
- [ ] Implement `AggregateSupplierKPIsJob`: nightly job at 02:00 UTC, iterates all suppliers, calculates metrics for prior month
- [ ] Implement municipal benchmark calculation: aggregate all suppliers' metrics, calculate average, store in KPI record
- [ ] Implement handling of insufficient data: mark as null or "Insufficient", skip from trend visualization
- [ ] Implement `KPIController` endpoints: GET /kpis (snapshot), GET /kpis/trends (12-month data), GET /kpis/export (CSV download)
- [ ] Test metric calculations with real invoice data
- [ ] Test insufficient data handling
- [ ] Test benchmark comparison
- [ ] Test CSV export with edge cases (sparse data, new suppliers)

---

## 4. API & Integration

### Task 4.1: API Security and Rate Limiting

**Spec ref**: REQ-009-B, REQ-009-C
**Files**:
- `lib/Middleware/SupplierAuthMiddleware.php` (new)
- `lib/Middleware/RateLimitMiddleware.php` (new)
- `lib/Middleware/AuditLoggingMiddleware.php` (new)

**Acceptance criteria**:
- GIVEN request without session WHEN submitted THEN 401 Unauthorized returned
- GIVEN supplier trying to access another supplier's case WHEN requested THEN 403 Forbidden returned
- GIVEN 100 requests in 1 minute from single IP WHEN submitted THEN 429 Too Many Requests returned
- GIVEN sensitive operation (invoice view, IBAN change) WHEN performed THEN audit log entry created with user, timestamp, action, data (masked PII)

- [ ] Implement `SupplierAuthMiddleware`: validates session token, injects current supplier into request context
- [ ] Implement `RateLimitMiddleware`: rate limit 100 requests/minute per IP, return 429 on excess
- [ ] Implement `AuditLoggingMiddleware`: logs all POST/PUT/DELETE requests with user, timestamp, action, old/new values (masked)
- [ ] Mask sensitive fields in logs: IBAN (show last 4 digits only), email (domain only), phone (partial)
- [ ] Test auth validation with expired/invalid tokens
- [ ] Test rate limiting with traffic simulation
- [ ] Test audit logging with various mutation types
- [ ] Verify no sensitive data (full IBAN, passwords) in logs

### Task 4.2: eHerkenning and KvK Integration (via OpenConnector)

**Spec ref**: REQ-001
**Files**: (None - coordinate with OpenConnector team)

**Acceptance criteria**:
- eHerkenning OAuth 2.0 flow integrated via OpenConnector
- KvK API validation working (check KvK number validity, company status)
- Real eHerkenning sandbox tested before production

**Coordination tasks**:
- [ ] Coordinate with OpenConnector team: eHerkenning broker endpoint, KvK API access, sandbox credentials
- [ ] Test OAuth flow in sandbox environment
- [ ] Verify KvK claim extraction and validation
- [ ] Prepare production migration plan (new eHerkenning credentials)

### Task 4.3: Procest Integration (Case Storage & Workflow)

**Spec ref**: REQ-003, REQ-005, REQ-006, REQ-007
**Files**: (OpenRegister REST API integration)

**Acceptance criteria**:
- Supplier cases (tenders, contracts, invoices) readable via OpenRegister REST
- Contract renewal requests create Procest zaak via REST
- IBAN changes trigger 4-eyes Procest workflow
- Master data mutations routable to correct teams

**Coordination tasks**:
- [ ] Coordinate with Procest team: OpenRegister REST endpoint, case type definitions, workflow definitions
- [ ] Define SupplierTender, SupplierContract, SupplierInvoice OpenRegister schemas/properties
- [ ] Define Procest case types: "Leverancier-contractverlenging-verzoek", "Leverancier-IBAN-wijziging", "Leverancier-accreditatie-verificatie", "Leverancier-mutatie"
- [ ] Test case creation and status transitions
- [ ] Prepare data migration (if converting from legacy systems)

### Task 4.4: Decidesk Integration (Payment Date Calculation)

**Spec ref**: REQ-004
**Files**: (Decidesk REST or event-based integration)

**Acceptance criteria**:
- Mandate routing delays retrieved from Decidesk
- Payment date forecast calculated correctly (invoice + routing + terms)
- Forecast updates when mandate routing changes

**Coordination tasks**:
- [ ] Coordinate with Decidesk team: API for mandate routing lookup, mandate schema details
- [ ] Test mandate routing query performance (integrate caching if needed)
- [ ] Test payment date calculation with various routing scenarios
- [ ] Prepare fallback logic (if Decidesk unavailable, use default 5-day delay)

### Task 4.5: Notification Integration

**Spec ref**: REQ-002, REQ-005, REQ-006, REQ-007
**Files**: (Event-based notification via Pipelinq or custom queue)

**Acceptance criteria**:
- Supplier invitations sent via email with activation link
- Contract renewal requests trigger email to account manager
- IBAN changes trigger email to finance team
- Message responses trigger email to supplier

**Coordination tasks**:
- [ ] Coordinate with Pipelinq team (if using): event dispatch for notifications
- [ ] Design notification templates (text + variables for each event type)
- [ ] Implement email service integration (Nextcloud/SMTP)
- [ ] Test email delivery for all notification types
- [ ] Prepare email template library

---

## 5. Testing

### Task 5.1: Unit Tests (Services, Utilities)

**Spec ref**: All
**Files**:
- `tests/Unit/SupplierAuthServiceTest.php` (new)
- `tests/Unit/SupplierScopeServiceTest.php` (new)
- `tests/Unit/InvoicePaymentForecastServiceTest.php` (new)
- `tests/Unit/SupplierKPIAggregationServiceTest.php` (new)

**Acceptance criteria**:
- All services have ≥ 80% code coverage
- Edge cases tested (boundary values, nulls, empty sets)
- Mocks used for external dependencies (Decidesk, OpenRegister, etc.)

- [ ] Write tests for `SupplierAuthService`: auth flow, KvK validation, session creation
- [ ] Write tests for `SupplierScopeService`: scope filtering, access validation
- [ ] Write tests for `InvoicePaymentForecastService`: payment date calculation with various mandate delays
- [ ] Write tests for `SupplierKPIAggregationService`: metric calculations (payment days, on-time %, dispute rate, compliance)
- [ ] Run test suite and verify ≥ 80% coverage
- [ ] Test edge cases: boundary dates, zero invoices, insufficient data

### Task 5.2: Integration Tests (API Endpoints)

**Spec ref**: All
**Files**:
- `tests/Feature/AuthApiTest.php` (new)
- `tests/Feature/TenderApiTest.php` (new)
- `tests/Feature/InvoiceApiTest.php` (new)
- `tests/Feature/ContractApiTest.php` (new)
- `tests/Feature/MessageApiTest.php` (new)
- `tests/Feature/ProfileApiTest.php` (new)
- `tests/Feature/KPIApiTest.php` (new)

**Acceptance criteria**:
- All API endpoints tested (happy path + error cases)
- Scope validation tested (supplier A cannot access supplier B's data)
- Rate limiting tested
- Audit logging verified

- [ ] Write tests for auth endpoints: login, callback, logout, refresh
- [ ] Write tests for tender endpoints: list (filtering/sorting), detail, report download
- [ ] Write tests for invoice endpoints: list, detail, age analysis, dispute
- [ ] Write tests for contract endpoints: list, detail, renewal request
- [ ] Write tests for message endpoints: send, list, detail
- [ ] Write tests for profile endpoints: address update, IBAN change, accreditations
- [ ] Write tests for KPI endpoints: snapshot, trends, export
- [ ] Test scope validation: supplier B should get 403 when accessing supplier A's case
- [ ] Test rate limiting: simulate 101 requests, verify 429 on 101st
- [ ] Test audit logging: verify mutations logged with old/new values
- [ ] Run full integration test suite

### Task 5.3: Frontend Component Tests

**Spec ref**: All frontend components
**Files**:
- `tests/Unit/components/*.spec.ts` (new)

**Acceptance criteria**:
- Key components have unit tests (login, dashboard, lists, forms)
- User interactions tested (clicks, form submissions, filters)
- Component rendering verified

- [ ] Write tests for DashboardTabs component: role-based tab visibility
- [ ] Write tests for TenderList: sorting, filtering, status badges
- [ ] Write tests for InvoiceList: age analysis buckets, filtering
- [ ] Write tests for ContractList: renewal warnings, list sorting
- [ ] Write tests for MessageComposer: message sending, attachment validation
- [ ] Write tests for KPIChart: metric display, trend visualization
- [ ] Run component test suite

### Task 5.4: End-to-End (E2E) Tests

**Spec ref**: Key user journeys
**Files**:
- `tests/E2E/supplierPortalFlow.spec.ts` (new)

**Acceptance criteria**:
- Full supplier journey tested (login → view cases → send message → update profile)
- Multi-user scenarios tested (invite, role changes)
- All major flows covered

- [ ] Write E2E test: Login via eHerkenning → Dashboard → View Tender → Download Report → Logout
- [ ] Write E2E test: Admin invites team member → Team member activates → Views allowed tabs
- [ ] Write E2E test: View Invoice → Send message → Receive response email
- [ ] Write E2E test: Contract renewal request workflow
- [ ] Write E2E test: Update address → Verify immediate application
- [ ] Write E2E test: Initiate IBAN change → Verify Procest zaak created
- [ ] Run E2E tests in staging environment
- [ ] Verify all flows execute successfully

### Task 5.5: Accessibility & Security Audit

**Spec ref**: REQ-009
**Files**: (Quality assurance, no code files)

**Acceptance criteria**:
- WCAG 2.1 AA audit passed (color contrast, keyboard nav, screen reader)
- Security audit passed (no XSS, CSRF, injection vulnerabilities)
- Penetration testing completed (before production)

- [ ] Run automated accessibility audit (Axe, Lighthouse)
- [ ] Manual keyboard navigation test (Tab through all pages, verify focus states)
- [ ] Manual screen reader test (NVDA, VoiceOver) for dashboard and forms
- [ ] Verify color contrast ≥ 4.5:1 for all text
- [ ] Run automated security scanning (SonarQube, CodeQL)
- [ ] Manual code review for XSS, CSRF, SQL injection vulnerabilities
- [ ] Penetration testing: attempt session hijacking, CORS bypass, rate limit bypass
- [ ] Fix all audit findings before production release

### Task 5.6: Performance Testing

**Spec ref**: All (implicit—portal should be fast)
**Files**: (Performance testing, load testing)

**Acceptance criteria**:
- Dashboard loads in < 2 seconds (90th percentile)
- Invoice list with 1000+ invoices loads in < 3 seconds
- Search/filter performance acceptable
- No memory leaks under load

- [ ] Load test: 100 concurrent users, 5-minute ramp-up, measure response times
- [ ] Test invoice list with 1000+ items: sorting, filtering, pagination
- [ ] Test chart rendering: 12-month KPI with data (no lag)
- [ ] Memory profiling: check for leaks after extended use
- [ ] Database query optimization: verify indexes on frequently queried fields (supplierRef, status, dates)
- [ ] Prepare performance tuning (caching, query optimization, CDN for static assets)

---

## 6. Deployment & Documentation

### Task 6.1: Database Migrations & Schema Seeding

**Spec ref**: All (data model)
**Files**:
- `database/migrations/xxxx_create_supplier_tables.php` (new)
- `database/seeders/SupplierSeeder.php` (new)

**Acceptance criteria**:
- Supplier, SupplierUser, SupplierTender, SupplierContract, SupplierInvoice, SupplierMessage, SupplierKPI tables created
- Indexes on frequently queried columns
- Seed data loaded for testing (3 suppliers with multi-user, tenders, contracts, invoices)

- [ ] Create migration files for all 7 entity tables
- [ ] Define indexes on supplierRef, status, dates
- [ ] Create seeder: load 3 suppliers, 5 users, 5 tenders, 4 contracts, 5 invoices, 1 message thread
- [ ] Test migration: `php artisan migrate`, verify tables created
- [ ] Test seeding: `php artisan db:seed`, verify data loaded
- [ ] Test rollback: `php artisan migrate:rollback`, verify tables dropped

### Task 6.2: Environment Configuration & Secrets

**Spec ref**: All (security)
**Files**:
- `.env.example` (update with new vars)
- `config/supplier-portal.php` (new)

**Acceptance criteria**:
- All external service URLs, API keys, secrets stored in `.env`
- No secrets committed to git
- `.env.example` template provided for new deployments

- [ ] Define `.env` variables: eHerkenning client ID/secret, Decidesk API key, Procest API URL, notification email templates, etc.
- [ ] Create `config/supplier-portal.php`: centralized config loading from `.env`
- [ ] Add `.env` to `.gitignore` (verify it's already ignored)
- [ ] Provide `.env.example` with placeholder values for team reference
- [ ] Document setup instructions for new developers

### Task 6.3: Documentation

**Spec ref**: All
**Files**:
- `docs/supplier-portal.md` (new)
- `docs/supplier-portal/api.md` (new)
- `docs/supplier-portal/deployment.md` (new)

**Acceptance criteria**:
- API documentation (endpoints, request/response schemas)
- Deployment guide (environment setup, database migration, service dependencies)
- User documentation (portal walkthrough, FAQ)

- [ ] Write API docs: list all endpoints, parameters, response schemas, examples
- [ ] Write deployment guide: prerequisites, environment variables, database setup, dependency service URLs
- [ ] Write user guide: dashboard overview, tender search, invoice tracking, messaging, KPI dashboard
- [ ] Create troubleshooting guide: common issues (eHerkenning failures, KvK validation, payment date calculation)
- [ ] Document team/role management for admin users
- [ ] Add screenshots/video walkthrough (optional)

### Task 6.4: Release Planning

**Spec ref**: All
**Files**: (Project management, no code)

**Acceptance criteria**:
- Release plan includes all tasks, dependencies, timeline
- Rollback plan prepared (if issues arise in production)
- Communication plan (notify municipalities, suppliers, staff)

- [ ] Create release checklist: all tasks complete, all tests passing, docs reviewed
- [ ] Plan staged rollout: start with 1-2 test municipalities, expand gradually
- [ ] Prepare rollback procedure: if critical issues, revert to previous version
- [ ] Plan communication: email to municipalities, suppliers, and staff with launch date and support contact
- [ ] Schedule post-launch support: on-call team for first 72 hours

---

## Acceptance Criteria Summary

- [ ] All 4 frontend pages fully functional (dashboard, tenders, invoices, contracts, messages, profile, KPI)
- [ ] All 7 backend services implemented and unit tested
- [ ] All API endpoints tested and spec-compliant
- [ ] Supplier scoping validated (no cross-supplier data leakage)
- [ ] eHerkenning login working with KvK validation
- [ ] Multi-user & role-based access working
- [ ] Payment date forecasting integrated with Decidesk
- [ ] Master data mutations (address auto-apply, IBAN 4-eyes) working
- [ ] KPI aggregation nightly job running and metrics correct
- [ ] WCAG 2.1 AA accessibility audit passed
- [ ] Security audit passed (no XSS, CSRF, injection)
- [ ] Performance testing completed (< 2s dashboard load)
- [ ] Full E2E test suite passing
- [ ] Staging environment deployment successful
- [ ] Documentation complete and reviewed
- [ ] Production deployment plan approved

---
