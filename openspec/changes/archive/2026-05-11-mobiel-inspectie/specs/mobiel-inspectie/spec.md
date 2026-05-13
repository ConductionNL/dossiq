---
status: implemented
---
# Mobiel Inspectie Specification

## Purpose

Mobiel Inspectie provides field inspectors with a Progressive Web App (PWA) for conducting inspections on location. Inspectors need to complete checklists, take photos, capture GPS coordinates, and add observations -- often in areas with poor or no connectivity. The app syncs data when back online.

**Tender demand**: 16% of tenders (11/69) explicitly require mobile inspection. It is a critical differentiator for VTH tenders -- mobile inspection is the primary tool for field inspectors at omgevingsdiensten.
**Standards**: PWA (Progressive Web App), Service Workers (offline), Geolocation API, MediaStream API (camera)
**Feature tier**: V2 (online PWA with photo/GPS), V3 (offline capability, sync queue, field signatures)

## ADDED Requirements
---

### Requirement: REQ-MOB-01 — PWA Installation and Access

The system MUST provide a Progressive Web App that inspectors can install on mobile devices and access from the browser. The PWA MUST integrate with Nextcloud authentication and launch in standalone mode for a native-like experience.

**Feature tier**: V2

#### Scenario: Install PWA on mobile device

- **GIVEN** an inspector accessing Procest from a mobile browser (Chrome/Safari)
- **WHEN** the inspector navigates to the inspectie module
- **THEN** the browser MUST offer "Add to Home Screen" via the PWA manifest
- **AND** the installed app MUST launch in standalone mode (no browser chrome)
- **AND** the app MUST use the Nextcloud authentication token for secure access

#### Scenario: Responsive layout for mobile

- **GIVEN** a screen width of 375px (mobile phone)
- **WHEN** the inspector views a case or checklist
- **THEN** all UI elements MUST be usable without horizontal scrolling
- **AND** touch targets MUST be at least 44x44px (WCAG 2.5.5)
- **AND** the primary actions (complete item, take photo, add note) MUST be accessible within one tap

#### Scenario: PWA manifest configuration

- **GIVEN** the Procest app deployed on a Nextcloud instance
- **WHEN** the PWA manifest is requested at `/apps/procest/manifest.json`
- **THEN** it MUST include: `name` ("Procest Inspectie"), `short_name` ("Inspectie"), `start_url` (inspectie module URL), `display` ("standalone"), `orientation` ("portrait"), `theme_color` (Nextcloud primary), `background_color` (white), icons at 192x192 and 512x512
- **AND** the HTML entry point MUST include `<link rel="manifest" href="/apps/procest/manifest.json">`

#### Scenario: Session persistence on PWA launch

- **GIVEN** an inspector who installed the PWA and previously logged into Nextcloud
- **WHEN** the inspector opens the PWA from the home screen 48 hours later
- **THEN** the session MUST still be active if the Nextcloud session token has not expired
- **AND** if the session has expired, the inspector MUST be redirected to the Nextcloud login page within the standalone PWA window

#### Scenario: Landscape mode for tablets

- **GIVEN** an inspector using a tablet in landscape orientation (1024x768)
- **WHEN** the inspector views a checklist
- **THEN** the layout MUST use a split view: checklist items on the left, detail/photo area on the right
- **AND** the split ratio MUST be adjustable via drag handle

---

### Requirement: REQ-MOB-02 — Inspection Task List

The system MUST display the inspector's assigned inspection tasks for the current day or configurable period, sourced from OpenRegister task objects assigned to the inspector.

**Feature tier**: V2

#### Scenario: Today's inspections

- **GIVEN** inspector "Pieter" with 4 inspections scheduled for today:
  - 09:00 Bouwtoezicht fase 1 -- Keizersgracht 100
  - 10:30 Milieucontrole -- Industrieweg 5
  - 13:00 Bouwtoezicht fase 2 -- Prinsengracht 50
  - 15:00 Horeca-inspectie -- Leidseplein 12
- **WHEN** Pieter opens the app
- **THEN** the task list MUST show all 4 inspections ordered by time
- **AND** each item MUST show: address, type, case reference, time
- **AND** each item MUST have a "Navigeer" button that opens the address in the device's map app

#### Scenario: Filter by date range

- **GIVEN** Pieter has 12 inspections scheduled across the current week
- **WHEN** Pieter selects the date filter and chooses "Deze week"
- **THEN** the task list MUST show all 12 inspections grouped by day
- **AND** each day header MUST show the date and number of inspections (e.g., "Maandag 16 maart -- 3 inspecties")

#### Scenario: Empty task list

- **GIVEN** inspector "Lisa" has no inspections scheduled for today
- **WHEN** Lisa opens the app
- **THEN** the task list MUST show an empty state message: "Geen inspecties gepland voor vandaag"
- **AND** a link to "Bekijk komende inspecties" that shows the next 7 days

#### Scenario: Task list data source from OpenRegister

- **GIVEN** inspection tasks are stored as OpenRegister objects in the `procest` register with the `task` schema
- **AND** task objects have `assignee` matching the current Nextcloud user ID
- **AND** task objects have `taskType` = "inspection"
- **WHEN** the app fetches the task list
- **THEN** it MUST query the OpenRegister API: `GET /api/objects?register={procest}&schema={task}&_filter[assignee]={userId}&_filter[taskType]=inspection&_order[scheduledDate]=asc`
- **AND** parse the response into the task list view

#### Scenario: Route optimization suggestion

- **GIVEN** inspector "Pieter" has 4 inspections at different addresses
- **WHEN** Pieter taps "Optimaliseer route"
- **THEN** the system MUST open the device's map app with all 4 addresses as waypoints
- **AND** the waypoints SHOULD be ordered to minimize travel distance (using browser geolocation as start point)

---

### Requirement: REQ-MOB-03 — Checklist Completion

The system MUST support completing inspection checklists on the mobile device. Checklists are defined as OpenRegister objects per case type and consist of categorized items with configurable response options.

**Feature tier**: V2

#### Scenario: Complete a checklist item

- **GIVEN** a checklist "Bouwtoezicht fase 1" with 8 items in 3 categories:
  - Fundering (3 items): Fundering conform tekening, Waterdichting aangebracht, Drainage aanwezig
  - Constructie (3 items): Wapening conform bestek, Betonkwaliteit gecontroleerd, Dekking wapening voldoende
  - Veiligheid (2 items): Bouwplaats afgezet, Veiligheidsmaatregelen getroffen
- **WHEN** the inspector selects item "Fundering conform tekening"
- **THEN** the inspector MUST be able to select: Conform / Niet-conform / Niet van toepassing
- **AND** add a free-text toelichting (max 2000 characters)
- **AND** the progress indicator MUST update: "3/8 items completed"

#### Scenario: Mandatory photo on non-conformity

- **GIVEN** a checklist item configured with "foto verplicht bij niet-conform"
- **WHEN** the inspector marks the item as "Niet-conform"
- **THEN** the system MUST require at least one photo before the item can be saved
- **AND** the photo MUST be captured via the device camera (not from gallery, for evidentiary integrity)
- **AND** the save button MUST be disabled with tooltip "Voeg minimaal 1 foto toe" until a photo is attached

#### Scenario: Checklist category navigation

- **GIVEN** a checklist with 25 items across 5 categories
- **WHEN** the inspector views the checklist
- **THEN** categories MUST be shown as collapsible sections with completion indicators (e.g., "Fundering 2/5")
- **AND** tapping a category header MUST expand/collapse that section
- **AND** a sticky header MUST show overall progress: "12/25 items (48%)"

#### Scenario: Checklist item with numeric measurement

- **GIVEN** a checklist item "Geluidsniveau (dB)" configured with response type "numeric" and range 0-120
- **WHEN** the inspector enters a value of 85
- **THEN** the value MUST be validated against the configured range
- **AND** if the value exceeds the threshold (e.g., >80 dB), a warning MUST be displayed: "Waarde overschrijdt norm"
- **AND** the measurement MUST be stored with the checklist response

#### Scenario: Resume partially completed checklist

- **GIVEN** an inspector who completed 5 of 8 checklist items and closed the app
- **WHEN** the inspector reopens the app and navigates to the same inspection
- **THEN** all 5 completed items MUST be shown with their previous responses
- **AND** the checklist MUST scroll to the first incomplete item
- **AND** a banner MUST show: "5 van 8 items ingevuld -- ga verder waar u bent gebleven"

---

### Requirement: REQ-MOB-04 — Photo and Document Capture

The system MUST support capturing photos and attaching them to inspection cases and specific checklist items. Photos MUST include metadata (GPS, timestamp) and be stored in Nextcloud Files.

**Feature tier**: V2

#### Scenario: Take inspection photo

- **GIVEN** an active inspection on case "2026-089"
- **WHEN** the inspector taps "Foto maken"
- **THEN** the device camera MUST open
- **AND** after capturing, the photo MUST be linked to: the case, the specific checklist item (if applicable), GPS coordinates, timestamp
- **AND** the photo MUST be uploaded to Nextcloud Files under the case folder at `/Procest/Zaken/2026-089/Inspecties/{inspectieId}/`

#### Scenario: Annotate photo

- **GIVEN** a captured photo of a building facade
- **WHEN** the inspector taps "Markeren"
- **THEN** the inspector MUST be able to draw arrows, circles, or rectangles on the photo to highlight issues
- **AND** select colors (red, yellow, green) for annotations
- **AND** the annotated version MUST be saved alongside the original (both stored in Nextcloud Files)
- **AND** the original MUST be preserved unmodified for evidentiary purposes

#### Scenario: Multiple photos per checklist item

- **GIVEN** a checklist item "Fundering conform tekening" marked as "Niet-conform"
- **WHEN** the inspector has captured 3 photos for this item
- **THEN** all 3 photos MUST be displayed as thumbnails below the checklist item
- **AND** each thumbnail MUST be tappable to view full-size
- **AND** a delete button (trash icon) MUST allow removing a photo with confirmation dialog
- **AND** the system MUST enforce a maximum of 10 photos per checklist item

#### Scenario: Photo metadata embedding

- **GIVEN** a photo captured during an inspection at GPS coordinates 52.3676, 4.8913
- **WHEN** the photo is saved
- **THEN** the following metadata MUST be stored in the OpenRegister photo object:
  - `capturedAt` (ISO 8601 timestamp)
  - `latitude` (52.3676)
  - `longitude` (4.8913)
  - `accuracy` (GPS accuracy in meters)
  - `caseId` (reference to the case)
  - `checklistItemId` (reference to the specific checklist item, if applicable)
  - `inspectorId` (Nextcloud user ID of the inspector)
- **AND** the EXIF data in the JPEG file MUST include GPS coordinates and timestamp

#### Scenario: Camera permission handling

- **GIVEN** an inspector who has not previously granted camera access
- **WHEN** the inspector taps "Foto maken" for the first time
- **THEN** the browser MUST prompt for camera permission via the MediaStream API
- **AND** if permission is denied, the system MUST display: "Camera toegang is vereist voor het maken van inspectie foto's. Ga naar apparaatinstellingen om toegang te verlenen."
- **AND** a fallback "Upload foto" button MUST allow selecting from the device gallery (with a warning that gallery photos lack evidentiary integrity)

---

### Requirement: REQ-MOB-05 — GPS Location Capture

The system MUST capture GPS coordinates during inspections for geographic verification and audit trail purposes.

**Feature tier**: V2

#### Scenario: Automatic location recording

- **GIVEN** an inspector starting an inspection at Keizersgracht 100
- **WHEN** the inspection is opened
- **THEN** the system MUST request GPS permission and record the current coordinates
- **AND** the coordinates MUST be stored on the inspection record (latitude, longitude, accuracy)
- **AND** if GPS is unavailable, the system MUST allow manual location confirmation

#### Scenario: Location mismatch warning

- **GIVEN** an inspection planned for Keizersgracht 100 (52.3676, 4.8913)
- **AND** the inspector's GPS shows coordinates >500m from the planned location
- **THEN** the system MUST display a warning: "Uw locatie wijkt af van het inspectieadres (afstand: {distance}m)"
- **AND** the inspector MUST confirm to proceed with reason selection: "Ander adres", "GPS onnauwkeurig", "Inspectie op afstand"
- **AND** the override reason MUST be stored in the inspection audit trail

#### Scenario: Continuous location tracking during inspection

- **GIVEN** an active inspection in progress
- **WHEN** the inspector moves between areas on a large site (e.g., construction site)
- **THEN** the system SHOULD record GPS coordinates at each checklist item completion
- **AND** a location trail MUST be available in the inspection report
- **AND** battery consumption MUST be minimized by using the Geolocation API watchPosition with `{ enableHighAccuracy: true, maximumAge: 30000, timeout: 10000 }`

#### Scenario: Indoor location fallback

- **GIVEN** an inspector inside a building where GPS signal is weak (accuracy >100m)
- **WHEN** the system detects poor GPS accuracy
- **THEN** it MUST display: "GPS signaal zwak -- locatie is mogelijk onnauwkeurig"
- **AND** the inspector MUST be able to manually pin the location on a map
- **AND** the manually pinned location MUST be flagged as `locationSource: "manual"` vs. `locationSource: "gps"`

#### Scenario: Map integration for planned inspections

- **GIVEN** a list of today's inspections with known addresses
- **WHEN** the inspector taps the map icon in the header
- **THEN** a map view MUST show all planned inspection locations as pins
- **AND** completed inspections MUST be shown with a green checkmark pin
- **AND** the inspector's current location MUST be shown as a blue dot
- **AND** tapping a pin MUST show the inspection summary with a "Start inspectie" button

---

### Requirement: REQ-MOB-06 — Offline Capability

The system MUST support offline operation for areas with no network connectivity. Data MUST be queued locally and synced when connectivity returns.

**Feature tier**: V3

#### Scenario: Work offline

- **GIVEN** the inspector has loaded today's inspections while online
- **WHEN** network connectivity is lost
- **THEN** the inspector MUST still be able to: view assigned inspections, complete checklist items, take photos, add notes
- **AND** a "Offline" indicator MUST be shown in the app header (orange banner)
- **AND** all changes MUST be stored in the browser's IndexedDB

#### Scenario: Sync when back online

- **GIVEN** 2 inspections completed offline with 6 photos and 16 checklist responses
- **WHEN** network connectivity is restored
- **THEN** the system MUST automatically sync all queued data to the server
- **AND** a sync progress indicator MUST show: "Synchroniseren: 6/6 foto's, 16/16 checklistitems"
- **AND** any sync conflicts MUST be flagged for manual resolution (server data wins by default)
- **AND** the sync status MUST transition through: "Wachten op verbinding" -> "Synchroniseren..." -> "Alles gesynchroniseerd"

#### Scenario: Pre-cache inspection data before going to field

- **GIVEN** an inspector viewing tomorrow's inspection schedule while online
- **WHEN** the inspector taps "Download voor offline gebruik"
- **THEN** the Service Worker MUST cache: all inspection task objects, all checklist templates, all case data referenced by inspections, all map tiles for inspection addresses (if supported)
- **AND** a progress indicator MUST show: "Offline data downloaden: 3/4 inspecties"
- **AND** the cached data MUST be stored in IndexedDB with a `cachedAt` timestamp

#### Scenario: Offline storage capacity management

- **GIVEN** the browser's IndexedDB storage limit (typically 50-100 MB per origin)
- **WHEN** cached offline data exceeds 80% of available storage
- **THEN** the system MUST display: "Opslagruimte bijna vol -- synchroniseer om ruimte vrij te maken"
- **AND** the system MUST prioritize: pending uploads > current inspection data > historical cache
- **AND** completed and synced inspections MUST be evictable from the cache

#### Scenario: Conflict resolution after offline sync

- **GIVEN** an inspector completed checklist item "Fundering conform tekening" as "Conform" offline
- **AND** a colleague updated the same item to "Niet-conform" on the server while the inspector was offline
- **WHEN** the offline data syncs
- **THEN** the system MUST detect the conflict and present both versions to the inspector
- **AND** the conflict dialog MUST show: the offline value, the server value, timestamps, and author for each
- **AND** the inspector MUST choose: "Mijn versie behouden", "Server versie accepteren", or "Beide bewaren als opmerking"

---

### Requirement: REQ-MOB-07 — Inspection Report Generation

The system MUST support generating a structured inspection report from the completed checklist, including all evidence (photos, measurements, notes).

**Feature tier**: V2

#### Scenario: Generate report on completion

- **GIVEN** an inspection with all checklist items completed
- **WHEN** the inspector taps "Inspectie afronden"
- **THEN** the system MUST generate a PDF report via Docudesk containing:
  - Header: inspection type, case reference, date/time, inspector name
  - Location: address, GPS coordinates, map thumbnail
  - Per checklist category: category name, per item: result (Conform/Niet-conform/N.v.t.), toelichting, embedded photos with annotations
  - Summary: total conform/niet-conform/n.v.t. counts, overall conclusion
  - Footer: inspector digital signature (if V3), generation timestamp
- **AND** the report MUST be stored in Nextcloud Files under the case folder
- **AND** the case status MAY automatically advance (if configured in the case type workflow)

#### Scenario: Draft report preview

- **GIVEN** an inspection with 6 of 8 checklist items completed
- **WHEN** the inspector taps "Voorvertoning rapport"
- **THEN** the system MUST generate a draft PDF with a "CONCEPT" watermark
- **AND** incomplete items MUST be highlighted in yellow
- **AND** the draft MUST NOT be stored as an official case document

#### Scenario: Report with non-conformities summary

- **GIVEN** an inspection where 3 of 8 items are marked "Niet-conform"
- **WHEN** the report is generated
- **THEN** the report MUST include a dedicated "Afwijkingen" section listing all non-conformities
- **AND** each non-conformity MUST include: item name, toelichting, photos, and a recommended follow-up action field
- **AND** the report conclusion MUST automatically be set to "Niet goedgekeurd" when any non-conformity exists

---

### Requirement: REQ-MOB-08 — Inspection Schema and Data Model

The system MUST define OpenRegister schemas for inspection entities: inspection, checklist template, checklist item template, checklist response, and inspection photo.

**Feature tier**: V2

#### Scenario: Inspection schema definition

- **GIVEN** the Procest app repair step runs (`InitializeSettings`)
- **THEN** the following schemas MUST be created in the `procest` register:
  - `inspection`: `{ caseId, taskId, inspectorId, scheduledDate, startedAt, completedAt, latitude, longitude, accuracy, locationSource, status, overallResult, reportDocumentId }`
  - `checklistTemplate`: `{ title, caseTypeId, categories, version, isActive }`
  - `checklistItemTemplate`: `{ checklistTemplateId, category, title, description, responseType (choice|numeric|text), choices (array), requiredPhotoOnFail, numericRange, sortOrder }`
  - `checklistResponse`: `{ inspectionId, checklistItemTemplateId, response, numericValue, toelichting, respondedAt, respondedBy }`
  - `inspectionPhoto`: `{ inspectionId, checklistResponseId, fileId, capturedAt, latitude, longitude, accuracy, hasAnnotation, annotatedFileId }`

#### Scenario: Checklist template versioning

- **GIVEN** a checklist template "Bouwtoezicht fase 1" at version 3
- **AND** 5 inspections have been completed using version 2
- **WHEN** an admin updates the checklist template
- **THEN** a new version 4 MUST be created (version 3 becomes immutable)
- **AND** existing inspections MUST retain their reference to the version used at the time of inspection
- **AND** new inspections MUST use the latest active version

#### Scenario: Admin creates a checklist template

- **GIVEN** an admin navigating to Procest Settings > Inspectie > Checklists
- **WHEN** the admin creates a new checklist for case type "Omgevingsvergunning"
- **THEN** the admin MUST be able to: add categories, add items per category, configure response type per item, set photo requirements, order items via drag-and-drop
- **AND** the template MUST be stored as an OpenRegister object with the `checklistTemplate` schema

---

### Requirement: REQ-MOB-09 — Digital Signature and Field Sign-off

The system MUST support capturing a digital signature from the inspector (and optionally the site responsible person) to sign off the inspection report.

**Feature tier**: V3

#### Scenario: Inspector signature capture

- **GIVEN** an inspector completing an inspection
- **WHEN** the inspector taps "Ondertekenen en afronden"
- **THEN** a signature canvas MUST appear (full-width, 200px height)
- **AND** the inspector MUST draw their signature using touch
- **AND** the signature MUST be saved as a PNG image and embedded in the PDF report
- **AND** the signature MUST include the signer's name and timestamp

#### Scenario: Third-party signature (site responsible)

- **GIVEN** an inspection that requires sign-off from the site responsible person
- **WHEN** the inspector taps "Handtekening derden"
- **THEN** the system MUST display: signer name input, signer role input, signature canvas
- **AND** the third-party signature MUST be stored separately and embedded in the report
- **AND** the inspector MUST confirm they witnessed the signature

#### Scenario: Refuse to sign

- **GIVEN** a site responsible person who refuses to sign the inspection report
- **WHEN** the inspector taps "Weigering registreren"
- **THEN** the system MUST record: refusal reason (free text), timestamp, inspector confirmation
- **AND** the report MUST include a "Handtekening geweigerd" section with the refusal reason

---

### Requirement: REQ-MOB-10 — Inspection Notifications and Reminders

The system MUST support push notifications to remind inspectors of upcoming inspections and alert them of schedule changes.

**Feature tier**: V2

#### Scenario: Morning briefing notification

- **GIVEN** inspector "Pieter" has 4 inspections scheduled for today
- **WHEN** the time is 07:00 on the inspection day
- **THEN** the system MUST send a push notification: "4 inspecties vandaag. Eerste: 09:00 Keizersgracht 100"
- **AND** tapping the notification MUST open the PWA to today's task list

#### Scenario: Inspection reminder 30 minutes before

- **GIVEN** an inspection scheduled for 10:30 at Industrieweg 5
- **WHEN** the time is 10:00
- **THEN** the system MUST send a push notification: "Inspectie over 30 min: Milieucontrole -- Industrieweg 5"
- **AND** the notification MUST include action buttons: "Navigeer" (opens map), "Details" (opens inspection)

#### Scenario: Schedule change notification

- **GIVEN** an inspection scheduled for 15:00 today
- **WHEN** the inspection is rescheduled by the coordinator to 09:00 tomorrow
- **THEN** the inspector MUST receive a push notification: "Inspectie verplaatst: Horeca-inspectie Leidseplein 12 -- nieuw: morgen 09:00"
- **AND** the task list MUST update to reflect the change (removing from today, adding to tomorrow)

---

### Requirement: REQ-MOB-11 — Inspection History and Follow-up

The system MUST maintain a complete inspection history per location and case, enabling inspectors to view previous findings and track follow-up actions.

**Feature tier**: V2

#### Scenario: View previous inspections at same address

- **GIVEN** the inspector is starting an inspection at Keizersgracht 100
- **AND** 2 previous inspections were conducted at this address (one 3 months ago, one 6 months ago)
- **WHEN** the inspector taps "Vorige inspecties"
- **THEN** the system MUST show a timeline of previous inspections with: date, inspector name, overall result (goedgekeurd/afgekeurd), number of non-conformities
- **AND** tapping an entry MUST show the full inspection report

#### Scenario: Follow-up action creation from non-conformity

- **GIVEN** an inspection item "Fundering conform tekening" marked as "Niet-conform"
- **WHEN** the inspector completes the inspection
- **THEN** the system MUST offer to create a follow-up task: "Herinspectie plannen voor: Fundering conform tekening"
- **AND** if confirmed, a new task of type "herinspectie" MUST be created in OpenRegister linked to the original inspection and case
- **AND** the follow-up task MUST include a deadline based on the case type's remediation period

#### Scenario: Compare current vs. previous inspection

- **GIVEN** a follow-up inspection at the same address
- **WHEN** the inspector views a checklist item that was previously "Niet-conform"
- **THEN** the system MUST highlight the item with: "Vorige inspectie: Niet-conform (12-01-2026)"
- **AND** the previous toelichting and photos MUST be viewable inline for comparison

---

### Requirement: REQ-MOB-12 — Accessibility and Usability

The mobile inspection app MUST be accessible and usable in field conditions, including bright sunlight, wet hands, and gloved operation.

**Feature tier**: V2

#### Scenario: High contrast mode for outdoor use

- **GIVEN** an inspector using the app in bright sunlight
- **WHEN** the inspector enables high-contrast mode (via toggle in the header)
- **THEN** all text MUST meet WCAG AAA contrast ratio (7:1)
- **AND** buttons MUST use solid dark backgrounds with white text
- **AND** the status bar indicators (Conform/Niet-conform) MUST use distinct shapes in addition to color (checkmark/cross) for colorblind accessibility

#### Scenario: Large touch targets for gloved use

- **GIVEN** an inspector wearing safety gloves on a construction site
- **WHEN** interacting with checklist items
- **THEN** all interactive elements MUST have a minimum touch target of 48x48px (above WCAG 2.5.5 minimum of 44x44px)
- **AND** the Conform/Niet-conform/N.v.t. buttons MUST be full-width with 56px height
- **AND** swipe gestures MUST be supported: swipe right for Conform, swipe left for Niet-conform

#### Scenario: Voice notes as alternative to typing

- **GIVEN** an inspector who needs to add a toelichting but cannot easily type (wet hands, gloves)
- **WHEN** the inspector taps the microphone icon on the toelichting field
- **THEN** the system MUST record audio via the MediaStream API
- **AND** the audio file MUST be attached to the checklist response as evidence
- **AND** optionally, the system MAY transcribe the audio to text using browser speech recognition API

---

## Dependencies

- **VTH Module spec** (`../vth-module/spec.md`): Inspection checklists are defined per VTH case type.
- **Case Management spec** (`../case-management/spec.md`): Inspections are tasks/activities within cases.
- **Task Management spec** (`../task-management/spec.md`): Inspection tasks appear in the inspector's task list.
- **Docudesk**: PDF report generation from checklist data.
- **Nextcloud Files**: Photo storage under case folder structure.
- **OpenRegister**: All inspection data stored as objects (inspection, checklist, response, photo schemas).
- **Nextcloud Push Notifications** (`OCP\Notification\IManager`): For inspection reminders and schedule changes.

### Current Implementation Status

**Not yet implemented.** No mobile inspection, PWA, checklist, or field inspection code exists in the Procest codebase. There are no inspection schemas, no PWA manifest, no service worker, and no mobile-specific Vue components.

**Foundation available:**
- Task management infrastructure (`src/views/tasks/TaskList.vue`, `src/views/tasks/TaskDetail.vue`) provides the task list model that inspection assignments could use.
- File upload support via `filesPlugin` in the object store for photo attachment.
- The `CnDetailPage` component used in case/task detail views provides sidebar and responsive layout foundations.
- Nextcloud Files integration for document storage under case folders.
- Docudesk (external dependency) for PDF report generation from checklist data.
- `MetricsController` and `HealthController` demonstrate the pattern for new API endpoints.

**Partial implementations:** None.

### Standards & References

- **PWA (Progressive Web App)**: W3C standard for installable web apps with offline capability.
- **Service Workers**: Browser API for offline caching and background sync (required for V3 offline capability).
- **IndexedDB**: Browser storage API for offline data persistence.
- **Geolocation API**: W3C standard for GPS coordinate capture.
- **MediaStream API (getUserMedia)**: Browser API for camera and microphone access.
- **WCAG 2.5.5**: Touch target size minimum 44x44px for mobile accessibility.
- **WCAG 2.1 Level AA**: Overall accessibility compliance target.
- **GEMMA VTH-referentiecomponenten**: Mobile inspection is part of the VTH reference architecture.
- **Omgevingswet**: Environmental and planning law requiring field inspections for permit compliance.
- **BIO**: Security requirements for mobile device data handling (device encryption, secure data transmission).
- **Web Push API**: W3C standard for push notifications to PWA.
- **Canvas API**: Browser API for photo annotation drawing features.
- **SpeechRecognition API**: Browser API for voice-to-text transcription.

### Specificity Assessment

This spec defines 12 requirements with 3-5 scenarios each, covering the full mobile inspection lifecycle from PWA installation through report generation. The V2/V3 tier split is maintained.

**Competitive context:** Dimpact ZAC and Flowable do not offer native mobile inspection PWAs. ZAC relies on third-party mobile inspection tools. Procest's built-in mobile inspection is a significant differentiator for VTH tenders.

**Open questions:**
1. Should the mobile inspection be a separate Vue app (PWA entry point) or part of the main Procest app with responsive layout?
2. How are checklists defined -- as OpenRegister schemas, JSON templates, or n8n workflow definitions?
3. What is the maximum number of photos per inspection (storage/bandwidth considerations)?
4. Should the PWA support Web Push notifications (requires VAPID key configuration)?
5. How does offline sync handle photo uploads -- queue all or sync progressively?
6. Should the PWA support multiple simultaneous offline inspections?
