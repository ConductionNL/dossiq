---
status: proposed
---

# pipelinq-client-management Specification

## Purpose
Define the client and request management domain for Pipelinq: clients, requests (verzoeken), and contacts. All entities are stored in OpenRegister under the Pipelinq register. Requests represent the pre-state of a case -- a yet-to-be-determined or incoming case before it enters formal case management in Procest. The client entity links organizations and persons across both Pipelinq (CRM) and Procest (case management) contexts.

## Context
Pipelinq serves as the CRM front door for Dutch municipalities and organizations using Nextcloud. Citizens, businesses, and other parties first appear as clients in Pipelinq, where their requests (verzoeken) are tracked. When a request matures into a formal case, it is converted into a Procest case with the client linked as a participant (betrokkene). This spec defines the data model, CRUD operations, and views for managing clients and requests, following the same thin-client architecture as Procest: all data in OpenRegister, Vue 2 frontend with Pinia stores, no backend CRUD controllers. The client entity maps to Schema.org `Person`/`Organization` and aligns with the ZGW Klantinteracties API standard and GEMMA KCC reference architecture.

## Requirements

### Requirement 1: Client-management schemas MUST be defined in the Pipelinq register
The Pipelinq register MUST include schemas for client, request, and contact entities, imported during app initialization.

#### Scenario 1.1: Client schema definition
- GIVEN the `pipelinq_register.json` configuration
- WHEN the register is imported via `ConfigurationService::importFromApp()`
- THEN a `client` schema MUST be created with properties: name (string, required), type (enum: person/organization, required), email (string/email format), phone (string), address (object with street, postalCode, city, country), notes (string), kvkNumber (string, for organizations), bsn (string, for persons -- stored encrypted), website (string/url), tags (array of strings), createdAt (datetime, auto), updatedAt (datetime, auto)
- AND the schema MUST include `x-schema-org-type: schema:Person` for person type and `schema:Organization` for organization type

#### Scenario 1.2: Request schema definition
- GIVEN the register configuration
- WHEN the register is imported
- THEN a `request` schema MUST be created with properties: title (string, required), description (string), client (string/reference to client, required), status (enum: new/in-progress/converted/closed, default: new), priority (enum: low/normal/high/urgent, default: normal), category (string), channel (enum: email/phone/web/counter/letter), requestedAt (datetime), convertedCaseId (string, reference to Procest case after conversion), assignee (string), notes (string), attachments (array of file references), activity (array of activity entries)

#### Scenario 1.3: Contact schema definition
- GIVEN the register configuration
- WHEN the register is imported
- THEN a `contact` schema MUST be created with properties: client (string/reference to client, required), name (string, required), role (string, e.g., "decision maker", "technical contact"), email (string/email), phone (string), isPrimary (boolean, default: false), notes (string)
- AND the schema MUST include `x-schema-org-type: schema:ContactPoint`

#### Scenario 1.4: Schema auto-configuration stores IDs
- GIVEN the schemas are imported
- WHEN `SettingsService::autoConfigureAfterImport()` runs
- THEN schema IDs for `client`, `request`, and `contact` MUST be stored in `IAppConfig` under keys `client_schema`, `request_schema`, `contact_schema`
- AND the object store MUST register these types during `initializeStores()`

#### Scenario 1.5: Existing register detection on re-enable
- GIVEN the Pipelinq register and schemas already exist from a previous installation
- WHEN the app is re-enabled
- THEN the repair step MUST detect existing schemas by slug
- AND it MUST NOT create duplicates
- AND it MUST update schema IDs in config if they have changed

### Requirement 2: Client list view MUST display paginated, searchable client overview
The frontend MUST display a list of clients with search, sort, filter, and sidebar capabilities using the shared library's `CnIndexPage` component.

#### Scenario 2.1: Client list rendering with CnIndexPage
- GIVEN the user navigates to `/apps/pipelinq/clients`
- WHEN `ClientList.vue` mounts and `useListView('client')` initializes
- THEN the composable MUST fetch the client schema and initial collection from OpenRegister
- AND `CnIndexPage` MUST render a data table with columns for: name, type (person/organization), email, phone, tags
- AND the list MUST support pagination via `@page-changed`

#### Scenario 2.2: Client search
- GIVEN the client list is displayed
- WHEN the user types "Gemeente Amsterdam" in the sidebar search
- THEN `fetchCollection('client', { _search: 'Gemeente Amsterdam' })` MUST be called
- AND results MUST update to show only matching clients

#### Scenario 2.3: Filter clients by type
- GIVEN the client list is displayed
- WHEN the user filters by type "organization" via the sidebar filter
- THEN `fetchCollection('client', { '_filters[type]': 'organization' })` MUST be called
- AND only organization-type clients MUST be shown

#### Scenario 2.4: Client row click navigates to detail
- GIVEN a client row in the list
- WHEN the user clicks the row
- THEN the router MUST navigate to `/apps/pipelinq/clients/:id` with the client's ID

#### Scenario 2.5: Create client from list
- GIVEN the user is on the client list
- WHEN the user clicks the "+" add button (CnIndexPage `@add` event)
- THEN a `ClientCreateDialog.vue` MUST open
- AND the dialog MUST include fields for: name (required), type (person/organization, required), email, phone, address fields, notes

### Requirement 3: Client detail view MUST display full client information with related data
The frontend MUST provide a comprehensive client detail view showing client information, related contacts, linked requests, and linked cases.

#### Scenario 3.1: Client detail page load
- GIVEN the user navigates to `/apps/pipelinq/clients/:id`
- WHEN `ClientDetail.vue` mounts
- THEN it MUST fetch the client via `fetchObject('client', clientId)`
- AND it MUST fetch contacts via `fetchCollection('contact', { '_filters[client]': clientId })`
- AND it MUST fetch requests via `fetchCollection('request', { '_filters[client]': clientId })`

#### Scenario 3.2: Client information card with editing
- GIVEN the client data is loaded
- WHEN the detail view renders
- THEN it MUST use `CnDetailPage` with `CnDetailCard` sections
- AND the client information card MUST show editable fields for: name, type, email, phone, address (street, postalCode, city, country), kvkNumber (if organization), website, notes, tags
- AND a Save button MUST persist changes via `saveObject('client', updatedData)`

#### Scenario 3.3: Contacts section
- GIVEN a client with 3 contacts
- WHEN the Contacts card renders
- THEN each contact MUST display: name, role, email, phone, isPrimary badge
- AND an "Add contact" button MUST open a dialog for creating a new contact linked to this client
- AND each contact MUST have edit and delete actions

#### Scenario 3.4: Requests section
- GIVEN a client with 5 requests
- WHEN the Requests card renders
- THEN each request MUST display: title, status (badge), priority (badge), channel, requestedAt date
- AND clicking a request MUST navigate to the request detail view
- AND a "New request" button MUST open the request create form with the client pre-selected

#### Scenario 3.5: Linked Procest cases (cross-app)
- GIVEN a client whose requests have been converted to Procest cases (convertedCaseId is populated)
- WHEN the client detail renders a "Cases" section
- THEN it MUST display each linked case with title, status, and identifier
- AND clicking a case link MUST navigate to `/apps/procest/cases/:caseId` (cross-app deep link)
- AND if Procest is not installed, the cases section MUST show a note explaining this

### Requirement 4: Client CRUD operations MUST work through OpenRegister
The frontend MUST support creating, editing, and deleting clients via the object store.

#### Scenario 4.1: Create person client
- GIVEN the user opens the client create dialog
- WHEN they fill in name "Jan de Vries", type "person", email "jan@example.nl", phone "06-12345678"
- THEN `saveObject('client', clientData)` MUST POST to OpenRegister
- AND the response MUST include server-assigned `id` and timestamps
- AND the new client MUST appear in the client list

#### Scenario 4.2: Create organization client with KVK number
- GIVEN the user creates an organization client
- WHEN they fill in name "Gemeente Amsterdam", type "organization", kvkNumber "12345678"
- THEN the client object MUST be saved with the kvkNumber field
- AND the kvkNumber MUST be validated as an 8-digit string

#### Scenario 4.3: Update client information
- GIVEN a client exists with ID `uuid-456`
- WHEN the user modifies the email and phone and saves
- THEN `saveObject('client', { id: 'uuid-456', ...updatedData })` MUST PUT to OpenRegister
- AND `updatedAt` MUST be refreshed server-side

#### Scenario 4.4: Delete client with dependency check
- GIVEN a client with 3 linked requests and 2 contacts
- WHEN the user clicks delete
- THEN a confirmation dialog MUST warn "This client has 3 requests and 2 contacts. Are you sure?"
- AND on confirm, `deleteObject('client', clientId)` MUST DELETE from OpenRegister
- AND linked contacts SHOULD be cascade-deleted (or orphaned with a warning)
- AND linked requests MUST NOT be deleted (they retain the client reference for audit)

#### Scenario 4.5: Validation on client create
- GIVEN the user attempts to create a client without a name
- WHEN validation runs
- THEN the name field MUST show an error "Name is required"
- AND the form MUST NOT submit
- AND the type field MUST also show an error if not selected

### Requirement 5: Request list view MUST display paginated, searchable request overview
The frontend MUST display a list of requests with search, sort, filter, and status indicators.

#### Scenario 5.1: Request list rendering
- GIVEN the user navigates to `/apps/pipelinq/requests`
- WHEN `RequestList.vue` mounts and `useListView('request')` initializes
- THEN the list MUST display columns: title, client name (resolved), status (badge with color), priority (badge), channel, requestedAt
- AND the list MUST support pagination and search

#### Scenario 5.2: Request status badges
- GIVEN requests with different statuses
- WHEN the status column renders
- THEN "new" MUST display a blue badge
- AND "in-progress" MUST display an orange badge
- AND "converted" MUST display a green badge with link to the case
- AND "closed" MUST display a gray badge

#### Scenario 5.3: Filter by status
- GIVEN the request list is displayed
- WHEN the user filters by status "new"
- THEN only new requests MUST be shown
- AND the filter MUST use `fetchCollection('request', { '_filters[status]': 'new' })`

#### Scenario 5.4: Filter by client
- GIVEN the request list
- WHEN the user filters by a specific client
- THEN only requests for that client MUST be shown
- AND the filter MUST use `_filters[client]` parameter

#### Scenario 5.5: Sort by priority and date
- GIVEN the request list
- WHEN the user sorts by priority descending
- THEN urgent requests MUST appear first, followed by high, normal, low
- AND within the same priority, newer requests MUST appear first (by requestedAt)

### Requirement 6: Request detail view MUST show full request information
The frontend MUST provide a request detail view with client link, status management, and conversion to case.

#### Scenario 6.1: Request detail page load
- GIVEN the user navigates to `/apps/pipelinq/requests/:id`
- WHEN `RequestDetail.vue` mounts
- THEN it MUST fetch the request via `fetchObject('request', requestId)`
- AND it MUST resolve the client reference to display client name and link

#### Scenario 6.2: Request information editing
- GIVEN the request is not in "converted" or "closed" status
- WHEN the detail view renders
- THEN it MUST show editable fields for: title, description, priority, category, channel, assignee, notes
- AND it MUST show read-only fields for: client (with link to client detail), status, requestedAt, convertedCaseId

#### Scenario 6.3: Request status transitions
- GIVEN a request with status "new"
- WHEN the user changes the status
- THEN the allowed transitions MUST be: new -> in-progress, new -> closed
- AND from "in-progress": in-progress -> converted (triggers case creation), in-progress -> closed
- AND "converted" and "closed" MUST be terminal states (no further transitions)

#### Scenario 6.4: Request activity timeline
- GIVEN a request with activity entries
- WHEN the activity section renders
- THEN it MUST display events chronologically: creation, status changes, notes, field updates
- AND adding a note MUST push to the request's activity array and save

#### Scenario 6.5: Request attachments
- GIVEN a request with file attachments
- WHEN the attachments section renders
- THEN each attachment MUST display filename, size, and download link
- AND the user MUST be able to upload new attachments via the files plugin
- AND attachments MUST be stored in OpenRegister's file storage for the request object

### Requirement 7: Request-to-case conversion MUST bridge Pipelinq and Procest
The system MUST support converting a Pipelinq request into a Procest case, linking the client as a participant.

#### Scenario 7.1: Convert request to case button
- GIVEN a request with status "in-progress"
- WHEN the detail view renders
- THEN a "Convert to case" button MUST be visible
- AND the button MUST be disabled if Procest is not installed

#### Scenario 7.2: Conversion dialog
- GIVEN the user clicks "Convert to case"
- WHEN the conversion dialog opens
- THEN it MUST allow selecting a Procest case type from available types (fetched from Procest's settings or cross-app API)
- AND it MUST pre-fill the case title from the request title
- AND it MUST display a summary of what will be created

#### Scenario 7.3: Case creation from request
- GIVEN the user confirms the conversion with a selected case type
- WHEN the conversion executes
- THEN a new case MUST be created in Procest's register via OpenRegister (using Procest's register/schema IDs)
- AND the case MUST include: title (from request), description (from request), caseType (selected), startDate (now), identifier (generated), status (initial for case type)
- AND a participant (role) object MUST be created in Procest linking the client as "initiator"

#### Scenario 7.4: Request updated after conversion
- GIVEN the case is successfully created
- WHEN the conversion completes
- THEN the request's `status` MUST be set to "converted"
- AND `convertedCaseId` MUST be set to the new case's ID
- AND the request's activity MUST include a "converted_to_case" entry with the case identifier

#### Scenario 7.5: Conversion failure rollback
- GIVEN the case creation fails (e.g., OpenRegister error)
- WHEN the conversion encounters an error
- THEN the request's status MUST NOT change (remain "in-progress")
- AND an error message MUST be displayed to the user
- AND no partial data (orphaned case or role) MUST remain in OpenRegister

### Requirement 8: Contact management MUST support multiple contacts per client
The frontend MUST support CRUD operations on contacts linked to a client.

#### Scenario 8.1: Contact list within client detail
- GIVEN a client with 4 contacts
- WHEN the Contacts card renders in ClientDetail
- THEN each contact MUST display: name, role, email, phone
- AND the primary contact MUST have a "Primary" badge
- AND contacts MUST be sorted with primary first, then alphabetically

#### Scenario 8.2: Create contact
- GIVEN the user clicks "Add contact" on a client detail
- WHEN the contact create dialog opens
- THEN it MUST include fields for: name (required), role, email, phone, isPrimary (checkbox)
- AND saving MUST call `saveObject('contact', { client: clientId, ...contactData })`

#### Scenario 8.3: Set primary contact
- GIVEN a client with 3 contacts, one marked as primary
- WHEN the user marks a different contact as primary
- THEN the old primary contact MUST have `isPrimary` set to `false`
- AND the new contact MUST have `isPrimary` set to `true`
- AND both updates MUST be saved to OpenRegister

#### Scenario 8.4: Edit contact
- GIVEN an existing contact
- WHEN the user edits the role and phone number
- THEN `saveObject('contact', { id: contactId, ...updatedData })` MUST PUT to OpenRegister

#### Scenario 8.5: Delete contact
- GIVEN a contact that is NOT the primary contact
- WHEN the user deletes the contact
- THEN `deleteObject('contact', contactId)` MUST remove it from OpenRegister
- AND the contact MUST disappear from the client detail's contact list
- AND if it IS the primary contact, a warning MUST appear: "This is the primary contact. Please set another contact as primary first."

### Requirement 9: Navigation MUST include clients and requests menu items
The app navigation MUST show menu items for all primary entity types.

#### Scenario 9.1: Navigation rendering with icons
- GIVEN the user opens the Pipelinq app
- WHEN `MainMenu.vue` renders within `NcAppNavigation`
- THEN the main list MUST include: Dashboard (dashboard icon), Clients (contacts/people icon), Requests (inbox/mail icon)
- AND a Documentation item MUST link externally

#### Scenario 9.2: Footer navigation with settings
- GIVEN the navigation footer
- WHEN it renders
- THEN it MUST include a Configuration/Settings item routing to the settings view

#### Scenario 9.3: Active route highlighting
- GIVEN the user is on the Clients list
- WHEN the navigation renders
- THEN the "Clients" menu item MUST be highlighted as active

#### Scenario 9.4: Localized navigation labels
- GIVEN a user with Dutch locale
- WHEN the navigation renders
- THEN it MUST show "Klanten" for Clients, "Verzoeken" for Requests, "Dashboard" for Dashboard

### Requirement 10: Client data MUST comply with privacy regulations
Client and contact data MUST be handled in compliance with AVG/GDPR, including personal data protection and access control.

#### Scenario 10.1: BSN field encrypted storage
- GIVEN a person-type client with a BSN (Burgerservicenummer)
- WHEN the client is saved to OpenRegister
- THEN the BSN MUST be stored in an encrypted field (using OpenRegister's encryption support)
- AND the BSN MUST only be visible to users with appropriate permissions

#### Scenario 10.2: Client data access restricted to authorized users
- GIVEN a regular user without CRM role
- WHEN they attempt to access client data
- THEN the system MUST enforce access control based on Nextcloud group membership or app-level permissions
- AND unauthorized users MUST receive a 403 response from the API

#### Scenario 10.3: Data export capability
- GIVEN a client requests their data (AVG right of access)
- WHEN the admin exports the client's data
- THEN the export MUST include all stored fields, contacts, and request history
- AND the export MUST be available as JSON or PDF

#### Scenario 10.4: Data deletion capability
- GIVEN a client requests data deletion (AVG right to erasure)
- WHEN the admin deletes the client
- THEN all personal data MUST be removed from OpenRegister
- AND contacts MUST be deleted
- AND request references MUST be anonymized (client field cleared, note added)

#### Scenario 10.5: Audit trail for personal data access
- GIVEN a user views a client's detail page
- WHEN the client data is fetched from OpenRegister
- THEN the audit trail plugin MUST record the access event
- AND the audit log MUST include: user, timestamp, object type, object ID, action (view/edit/delete)

### Requirement 11: Client deduplication MUST prevent duplicate entries
The system MUST provide mechanisms to detect and merge duplicate client records.

#### Scenario 11.1: Duplicate detection on create
- GIVEN a user creates a new client with name "Gemeente Amsterdam"
- WHEN the create form is submitted
- THEN the system MUST check for existing clients with matching name (case-insensitive)
- AND if potential duplicates are found, a warning MUST be displayed: "Similar clients found: [list]. Continue creating or view existing?"

#### Scenario 11.2: Duplicate detection by email
- GIVEN a user creates a client with email "info@amsterdam.nl"
- WHEN the create form is submitted
- THEN the system MUST check for existing clients with the same email
- AND if found, a warning MUST be displayed with a link to the existing client

#### Scenario 11.3: Duplicate detection by KVK number
- GIVEN a user creates an organization client with kvkNumber "12345678"
- WHEN the create form is submitted
- THEN the system MUST check for existing organizations with the same KVK number
- AND if found, the system MUST prevent creation with error: "An organization with this KVK number already exists"

#### Scenario 11.4: Merge duplicate clients (V1)
- GIVEN two client records for the same entity
- WHEN the admin selects both and clicks "Merge"
- THEN the system MUST present a merge dialog showing fields from both records
- AND the admin MUST choose which fields to keep for each conflicting field
- AND after merge, all requests and contacts from both records MUST be linked to the surviving record
- AND the duplicate record MUST be deleted

### Requirement 12: Cross-app client resolution between Pipelinq and Procest
When a client appears in both Pipelinq and Procest (as a case participant), the system MUST provide cross-referencing capabilities.

#### Scenario 12.1: Client profile shows Procest cases
- GIVEN a client in Pipelinq whose requests have been converted to Procest cases
- WHEN viewing the client detail
- THEN a "Cases" section MUST query Procest's register for cases where the client appears as a participant
- AND each case MUST show: identifier, title, status, case type
- AND the query MUST use OpenRegister cross-register filtering (filter by client ID in Procest role objects)

#### Scenario 12.2: Procest case detail shows client from Pipelinq
- GIVEN a Procest case with a participant that references a Pipelinq client
- WHEN the case detail's ParticipantsSection renders
- THEN the participant's name MUST be resolved from the Pipelinq client object
- AND clicking the participant name MUST deep-link to `/apps/pipelinq/clients/:clientId`

#### Scenario 12.3: Client not found in cross-app query
- GIVEN a case participant that references a client ID that no longer exists in Pipelinq
- WHEN the cross-app resolution attempts to fetch the client
- THEN it MUST handle the 404 gracefully
- AND display the raw participant name instead of a resolved link
- AND log a warning about the orphaned reference

---

## Current Implementation Status

**Not implemented in the current Pipelinq app.** The Pipelinq app exists as a submodule at `pipelinq/` but is focused on lead/prospect/pipeline management rather than client/request management as described in this spec.

**Current Pipelinq entity model** (in `pipelinq/src/store/modules/`):
- `object.js` -- generic object store (same pattern as Procest)
- `settings.js` -- app settings
- `leadSources.js` -- lead source configuration
- `requestChannels.js` -- request channel configuration
- `product.js` -- product management
- `prospect.js` -- prospect/lead management

**What exists as foundation:**
- The `createObjectStore('object')` pattern is in place and ready for new types
- `initializeStores()` in `store/store.js` registers types from settings config
- The repair step (`InitializeSettings.php`) and settings service are functional
- Navigation and router are configured and can be extended with new routes
- `CnIndexPage` and `CnDetailPage` from the shared library are available for building list/detail views

**What needs to be built:**
- Client, request, and contact schema definitions in `pipelinq_register.json`
- `ClientList.vue`, `ClientDetail.vue`, `ClientCreateDialog.vue`
- `RequestList.vue`, `RequestDetail.vue`
- Contact management components within client detail
- Request-to-case conversion flow (cross-app bridge to Procest)
- Navigation items for Clients and Requests
- Privacy compliance features (BSN encryption, access control, data export/deletion)
- Duplicate detection and merge functionality
- Cross-app client resolution between Pipelinq and Procest

## Standards & References

- **ZGW Klantinteracties API (VNG)**: Client/contact management aligns with the Klantinteracties standard for Dutch government systems.
- **GEMMA KCC**: Klantcontactcentrum reference architecture -- Pipelinq serves as the KCC intake component.
- **Schema.org**: Clients map to `schema:Person` or `schema:Organization`, contacts to `schema:ContactPoint`, requests to `schema:Request`.
- **AVG/GDPR**: Client and contact personal data requires encryption (BSN), access control, data export, and deletion capabilities.
- **KVK (Kamer van Koophandel)**: Organization identification via 8-digit KVK number.
- **Common Ground**: Data layer in OpenRegister, CRM layer in Pipelinq, case layer in Procest.
- **CMMN 1.1**: Request-to-case conversion maps to the CMMN CaseFileItem creation pattern.
- **WCAG AA**: All client and request views must be accessible.
- **NL Design System**: CSS variables for government theming.

## Specificity Assessment

This spec is comprehensive with 12 requirements covering schemas, client CRUD, client detail with contacts/requests/cases, request CRUD, request-to-case conversion, contact management, navigation, privacy compliance, deduplication, and cross-app resolution. The spec defines both the Pipelinq-internal features and the critical cross-app bridge to Procest.

**Key design decisions:**
- Client/request/contact schemas live in the Pipelinq register (not a separate register).
- Requests have a simple 4-state lifecycle (new -> in-progress -> converted/closed).
- Request-to-case conversion creates objects in Procest's register (cross-register write).
- BSN is stored encrypted, with access control enforcement.
- Duplicate detection uses name, email, and KVK number matching.
- Cross-app resolution queries OpenRegister across registers.

**Feature tiers:**
- MVP: Client CRUD, Request CRUD, Navigation, Settings
- V1: Request-to-case conversion, Contact management, Cross-app resolution, Deduplication
- Enterprise: Privacy compliance (BSN encryption, data export/deletion, audit trails)
